package ingest

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"hash/crc32"
	"log/slog"
	"math"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/tharindarodrigo/iot-portal/services/telemetry-ingester/internal/config"
)

type Store interface {
	ResolveTopic(context.Context, string) (*ResolvedTopic, error)
	ResolveBindings(context.Context, string) ([]Binding, error)
	CreateQueuedMessage(context.Context, Envelope) (Message, bool, error)
	LogStage(context.Context, StageLog) error
	FinalizeMessage(context.Context, string, map[string]any) error
	PersistTelemetry(context.Context, Message, ResolvedTopic, map[string]any, map[string]any, map[string]any, map[string]any, string, string, time.Time) (PersistedTelemetry, error)
	MarkOnline(context.Context, int64, time.Time) error
}

type EventPublisher interface {
	PublishIncoming(context.Context, Envelope) error
	PublishPersisted(context.Context, PersistedTelemetry, Message, ResolvedTopic) error
	PublishHotState(context.Context, PersistedTelemetry, Message, ResolvedTopic, string) error
	PublishAnalytics(context.Context, PersistedTelemetry, Message, ResolvedTopic) error
}

type Pipeline struct {
	store     Store
	publisher EventPublisher
	config    config.Config
	logger    *slog.Logger
}

func NewPipeline(store Store, publisher EventPublisher, cfg config.Config, logger *slog.Logger) *Pipeline {
	return &Pipeline{store: store, publisher: publisher, config: cfg, logger: logger}
}

func (p *Pipeline) Process(ctx context.Context, envelope Envelope) error {
	if !p.config.Enabled || p.config.Driver != "go" {
		return nil
	}

	expanded, err := p.expandBindings(ctx, envelope)
	if err != nil {
		return err
	}
	if len(expanded) == 0 {
		expanded = []Envelope{envelope}
	}

	for _, item := range expanded {
		if err := p.publisher.PublishIncoming(ctx, item); err != nil {
			p.logger.Warn("incoming telemetry event publish failed", "topic", item.MQTTTopic, "error", err)
		}
		if err := p.ingestOne(ctx, item); err != nil {
			return err
		}
	}

	return nil
}

func (p *Pipeline) ingestOne(ctx context.Context, envelope Envelope) error {
	message, shouldContinue, err := p.store.CreateQueuedMessage(ctx, envelope)
	if err != nil {
		return err
	}
	if !shouldContinue {
		return nil
	}

	resolved, err := p.store.ResolveTopic(ctx, envelope.MQTTTopic)
	if err != nil {
		return err
	}
	if resolved == nil {
		errorsMap := map[string]any{"reason": "channel_not_registered", "address": envelope.MQTTTopic}
		_ = p.logStage(ctx, message.ID, StageIngress, StatusFailedTerminal, nil, map[string]any{
			"source_subject": envelope.SourceSubject,
			"address":        envelope.MQTTTopic,
			"payload":        envelope.Payload,
		}, nil, errorsMap)
		return p.store.FinalizeMessage(ctx, message.ID, map[string]any{
			"status":        StatusFailedTerminal,
			"error_summary": errorsMap,
			"processed_at":  time.Now().UTC(),
		})
	}

	_ = p.logStage(ctx, message.ID, StageIngress, StatusCompleted, nil, map[string]any{
		"source_subject": envelope.SourceSubject,
		"address":        envelope.MQTTTopic,
	}, map[string]any{
		"device_id":                 resolved.Device.ID,
		"device_channel_id":         resolved.Channel.ID,
		"device_profile_version_id": resolved.Device.ProfileVersionID,
	}, nil)

	started := time.Now()
	validation := validatePayload(envelope.Payload, resolved.Parameters)
	processingState := "processed"
	status := StatusCompleted
	finalValues := map[string]any{}
	mutatedValues := map[string]any{}

	if validation.IsInvalid {
		processingState = "invalid"
		status = StatusFailedValidation
		finalValues = validation.ExtractedValues
	} else if !resolved.Device.IsActive {
		processingState = "inactive_skipped"
		status = StatusInactiveSkipped
		finalValues = validation.ExtractedValues
	} else {
		mutatedValues = mutateValues(validation.ExtractedValues, resolved.Parameters)
		finalValues = deriveValues(mutatedValues, resolved.Derived)
	}

	telemetry, err := p.store.PersistTelemetry(
		ctx,
		message,
		*resolved,
		envelope.Payload,
		finalValues,
		mutatedValues,
		validation.ValidationErrors,
		validation.Status,
		processingState,
		envelope.ReceivedAt,
	)
	if err != nil {
		return err
	}

	duration := time.Since(started).Milliseconds()
	_ = p.logStage(ctx, message.ID, StagePersist, status, &duration, nil, map[string]any{
		"device_telemetry_log_id": telemetry.ID,
		"processing_state":        processingState,
		"validation_status":       validation.Status,
		"transformed_values":      finalValues,
	}, validation.ValidationErrors)

	finalValuesForSummary := map[string]any{}
	if len(validation.ValidationErrors) > 0 {
		finalValuesForSummary["validation_errors"] = validation.ValidationErrors
	}

	if err := p.store.FinalizeMessage(ctx, message.ID, map[string]any{
		"organization_id":           resolved.Device.OrganizationID,
		"device_id":                 resolved.Device.ID,
		"device_profile_version_id": resolved.Device.ProfileVersionID,
		"device_channel_id":         resolved.Channel.ID,
		"status":                    status,
		"error_summary":             nullableMap(finalValuesForSummary),
		"processed_at":              time.Now().UTC(),
	}); err != nil {
		return err
	}

	if processingState != "inactive_skipped" {
		if err := p.store.MarkOnline(ctx, resolved.Device.ID, envelope.ReceivedAt); err != nil {
			p.logger.Warn("device presence update failed", "device_id", resolved.Device.ID, "error", err)
		}
	}

	if err := p.publisher.PublishHotState(ctx, telemetry, message, *resolved, status); err != nil {
		p.logger.Warn("hot-state publish failed", "telemetry_log_id", telemetry.ID, "error", err)
	}

	if err := p.publisher.PublishAnalytics(ctx, telemetry, message, *resolved); err != nil {
		p.logger.Warn("analytics publish failed", "telemetry_log_id", telemetry.ID, "error", err)
	}

	if err := p.publisher.PublishPersisted(ctx, telemetry, message, *resolved); err != nil {
		p.logger.Warn("persisted telemetry event publish failed", "telemetry_log_id", telemetry.ID, "error", err)
	}

	return nil
}

func (p *Pipeline) expandBindings(ctx context.Context, envelope Envelope) ([]Envelope, error) {
	bindings, err := p.store.ResolveBindings(ctx, envelope.MQTTTopic)
	if err != nil || len(bindings) == 0 {
		return nil, err
	}

	grouped := map[string][]Binding{}
	for _, binding := range bindings {
		key := bindingKey(binding)
		grouped[key] = append(grouped[key], binding)
	}

	expanded := make([]Envelope, 0, len(grouped))
	for _, group := range grouped {
		first := group[0]
		payload := map[string]any{}
		mapped := 0
		for _, binding := range group {
			sourceValue, ok := extractBindingValue(envelope.Payload, binding)
			if !ok {
				continue
			}
			value := coerceForParameter(sourceValue, binding.ParameterType)
			setValueAtPath(payload, binding.TargetJSONPath, value)
			mapped++
		}
		if mapped == 0 {
			continue
		}

		topic := resolvedChannelAddress(first.Channel, first.Device)
		expanded = append(expanded, Envelope{
			SourceSubject:    envelope.SourceSubject + "#" + topic,
			MQTTTopic:        topic,
			Payload:          payload,
			DeviceUUID:       first.Device.UUID,
			DeviceExternalID: first.Device.ExternalID,
			MessageID:        envelope.MessageID,
			ReceivedAt:       envelope.ReceivedAt,
		})
	}

	return expanded, nil
}

func (p *Pipeline) logStage(ctx context.Context, messageID, stage, status string, durationMS *int64, input, output, errorsMap map[string]any) error {
	if status == StatusCompleted {
		switch p.config.StageLogMode {
		case "all":
		case "sampled":
			if !sample(messageID, p.config.StageLogSampleRate) {
				return nil
			}
		default:
			return nil
		}
	}

	if !p.config.CaptureStageSnapshots && status == StatusCompleted {
		input = nil
		output = nil
	}

	return p.store.LogStage(ctx, StageLog{
		MessageID:      messageID,
		Stage:          stage,
		Status:         status,
		DurationMS:     durationMS,
		InputSnapshot:  input,
		OutputSnapshot: output,
		Errors:         nullableMap(errorsMap),
	})
}

func validatePayload(payload map[string]any, parameters []Parameter) ValidationResult {
	extracted := map[string]any{}
	validationErrors := map[string]any{}
	hasInvalid := false
	hasCritical := false

	for _, parameter := range parameters {
		if !parameter.IsActive {
			continue
		}
		value, found := valueAtPath(payload, parameter.JSONPath)
		if !found {
			value = nil
		}
		extracted[parameter.Key] = value

		valid, code := validateValue(value, parameter)
		if valid {
			continue
		}
		hasInvalid = true
		if parameter.IsCritical {
			hasCritical = true
		}
		validationErrors[parameter.Key] = map[string]any{
			"error_code":  code,
			"is_critical": parameter.IsCritical,
		}
	}

	status := "valid"
	if hasCritical {
		status = "invalid"
	} else if hasInvalid {
		status = "warning"
	}

	return ValidationResult{
		ExtractedValues:  extracted,
		ValidationErrors: validationErrors,
		Status:           status,
		IsInvalid:        status == "invalid",
	}
}

func validateValue(value any, parameter Parameter) (bool, string) {
	code := parameter.ValidationErrorCode
	if code == "" {
		code = "invalid_" + parameter.Key
	}

	if parameter.Required && (value == nil || value == "") {
		return false, code
	}
	if value == nil || value == "" {
		return true, ""
	}
	if !matchesType(value, parameter.Type) {
		return false, code
	}

	if min, ok := numericRule(parameter.ValidationRules, "min"); ok && toFloat(value) < min {
		return false, code
	}
	if max, ok := numericRule(parameter.ValidationRules, "max"); ok && toFloat(value) > max {
		return false, code
	}
	if pattern, ok := stringRule(parameter.ValidationRules, "regex"); ok {
		matched, err := regexp.MatchString(pattern, stringify(value))
		if err != nil || !matched {
			return false, code
		}
	}
	if enumValues, ok := parameter.ValidationRules["enum"].([]any); ok {
		for _, enumValue := range enumValues {
			if enumValue == value {
				return true, ""
			}
		}
		return false, code
	}

	return true, ""
}

func matchesType(value any, parameterType string) bool {
	switch parameterType {
	case "integer":
		number, ok := numericValue(value)

		return ok && math.Trunc(number) == number
	case "decimal":
		_, ok := numericValue(value)

		return ok
	case "boolean":
		_, ok := value.(bool)
		return ok
	case "string":
		_, ok := value.(string)
		return ok
	case "json":
		return true
	default:
		return true
	}
}

func mutateValues(extracted map[string]any, parameters []Parameter) map[string]any {
	mutated := map[string]any{}
	for _, parameter := range parameters {
		before := extracted[parameter.Key]
		if parameter.MutationExpression != nil {
			mutated[parameter.Key] = evalJSONLogic(parameter.MutationExpression, map[string]any{"val": before})
		} else {
			mutated[parameter.Key] = before
		}
	}
	return mutated
}

func deriveValues(mutated map[string]any, derived []DerivedParameter) map[string]any {
	finalValues := map[string]any{}
	for key, value := range mutated {
		finalValues[key] = value
	}

	pending := append([]DerivedParameter{}, derived...)
	for i := 0; i < len(derived) && len(pending) > 0; i++ {
		nextPending := make([]DerivedParameter, 0, len(pending))
		progress := false
		for _, definition := range pending {
			if !dependenciesResolved(definition.Dependencies, finalValues) {
				nextPending = append(nextPending, definition)
				continue
			}
			finalValues[definition.Key] = evalJSONLogic(definition.Expression, finalValues)
			progress = true
		}
		if !progress {
			break
		}
		pending = nextPending
	}

	return finalValues
}

func dependenciesResolved(dependencies []string, values map[string]any) bool {
	for _, dependency := range dependencies {
		if _, ok := values[dependency]; !ok {
			return false
		}
	}
	return true
}

func DeduplicationKey(envelope Envelope) string {
	if strings.TrimSpace(envelope.MessageID) != "" {
		return sha256String(envelope.SourceSubject + "|" + strings.TrimSpace(envelope.MessageID))
	}
	payload, _ := json.Marshal(envelope.Payload)
	return sha256String(envelope.SourceSubject + "|" + string(payload) + "|" + envelope.ReceivedAt.UTC().Format(time.RFC3339))
}

func NewMessageID() string {
	return uuid.NewString()
}

func sample(key string, rate float64) bool {
	if rate <= 0 {
		return false
	}
	if rate >= 1 {
		return true
	}
	return float64(crc32.ChecksumIEEE([]byte(key)))/float64(math.MaxUint32) < rate
}

func sha256String(value string) string {
	hash := sha256.Sum256([]byte(value))
	return hex.EncodeToString(hash[:])
}

func numericRule(rules map[string]any, key string) (float64, bool) {
	if rules == nil {
		return 0, false
	}
	value, ok := rules[key]
	if !ok {
		return 0, false
	}
	return toFloat(value), true
}

func numericValue(value any) (float64, bool) {
	switch typed := value.(type) {
	case int, int64, float64, float32:
		return toFloat(typed), true
	case string:
		trimmed := strings.TrimSpace(typed)
		if trimmed == "" {
			return 0, false
		}
		parsed, err := strconv.ParseFloat(trimmed, 64)

		return parsed, err == nil
	default:
		return 0, false
	}
}

func stringRule(rules map[string]any, key string) (string, bool) {
	if rules == nil {
		return "", false
	}
	value, ok := rules[key].(string)
	return value, ok
}

func nullableMap(value map[string]any) map[string]any {
	if len(value) == 0 {
		return nil
	}
	return value
}

func stringify(value any) string {
	if value == nil {
		return ""
	}
	return strings.TrimSpace(strings.ReplaceAll(strings.Trim(strings.TrimSpace(toJSON(value)), "\""), "\\\"", "\""))
}

func toJSON(value any) string {
	encoded, err := json.Marshal(value)
	if err != nil {
		return ""
	}
	return string(encoded)
}

func bindingKey(binding Binding) string {
	return resolvedChannelAddress(binding.Channel, binding.Device)
}

func extractBindingValue(payload map[string]any, binding Binding) (any, bool) {
	path := binding.SourceJSONPath
	if path == "" || path == "$" {
		return nil, false
	}
	return valueAtPath(payload, path)
}

func coerceForParameter(value any, parameterType string) any {
	switch parameterType {
	case "integer":
		if boolValue, ok := value.(bool); ok {
			if boolValue {
				return 1
			}
			return 0
		}
		return int64(toFloat(value))
	case "decimal":
		if boolValue, ok := value.(bool); ok {
			if boolValue {
				return 1.0
			}
			return 0.0
		}
		return toFloat(value)
	case "boolean":
		switch typed := value.(type) {
		case bool:
			return typed
		case string:
			return typed == "1" || strings.EqualFold(typed, "true")
		default:
			return toFloat(value) == 1
		}
	case "string":
		return stringify(value)
	default:
		return value
	}
}

func resolvedChannelAddress(channel Channel, device Device) string {
	identifier := strings.TrimSpace(device.ExternalID)
	if identifier == "" {
		identifier = device.UUID
	}
	address := strings.Trim(channel.Address, "/")
	if strings.Contains(address, "{") {
		replacer := strings.NewReplacer(
			"{device}", identifier,
			"{device_id}", identifier,
			"{device_external_id}", identifier,
			"{device_uuid}", device.UUID,
		)
		return replacer.Replace(address)
	}
	if !strings.Contains(address, "/") {
		baseTopic := strings.Trim(device.BaseTopic, "/")
		if baseTopic == "" {
			baseTopic = "device"
		}
		return baseTopic + "/" + identifier + "/" + address
	}
	return address
}

var ErrDuplicate = errors.New("duplicate ingestion message")
