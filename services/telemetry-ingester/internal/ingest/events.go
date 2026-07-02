package ingest

import (
	"context"
	"encoding/json"
	"errors"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/nats-io/nats.go"
	"github.com/tharindarodrigo/iot-portal/services/telemetry-ingester/internal/config"
)

type NATSEventPublisher struct {
	nc            *nats.Conn
	sideEffectsNC *nats.Conn
	config        config.Config
}

func NewNATSEventPublisher(nc *nats.Conn, sideEffectsNC *nats.Conn, cfg config.Config) *NATSEventPublisher {
	if sideEffectsNC == nil {
		sideEffectsNC = nc
	}

	return &NATSEventPublisher{nc: nc, sideEffectsNC: sideEffectsNC, config: cfg}
}

func (p *NATSEventPublisher) PublishIncoming(_ context.Context, envelope Envelope) error {
	payload := map[string]any{
		"topic":              envelope.MQTTTopic,
		"source_subject":     envelope.SourceSubject,
		"device_uuid":        envelope.DeviceUUID,
		"device_external_id": envelope.DeviceExternalID,
		"payload":            envelope.Payload,
		"received_at":        envelope.ReceivedAt.UTC().Format(time.RFC3339),
	}
	return p.publish(p.config.IncomingEventSubject, payload)
}

func (p *NATSEventPublisher) PublishPersisted(_ context.Context, telemetry PersistedTelemetry, message Message, resolved ResolvedTopic) error {
	payload := map[string]any{
		"telemetry_log_id":     telemetry.ID,
		"ingestion_message_id": message.ID,
		"device_id":            resolved.Device.ID,
		"device_uuid":          resolved.Device.UUID,
		"device_external_id":   resolved.Device.ExternalID,
		"device_channel_id":    resolved.Channel.ID,
		"processing_state":     telemetry.ProcessingState,
		"validation_status":    telemetry.ValidationStatus,
	}
	return p.publish(p.config.PersistedEventSubject, payload)
}

func (p *NATSEventPublisher) PublishHotState(_ context.Context, telemetry PersistedTelemetry, message Message, resolved ResolvedTopic, status string) error {
	if !p.config.WriteHotState || telemetry.ProcessingState != "processed" {
		return nil
	}

	kv, err := p.hotStateBucket()
	if err != nil {
		return err
	}

	document := map[string]any{
		"topics": map[string]any{},
	}

	entry, err := kv.Get(resolved.Device.UUID)
	if err != nil && !errors.Is(err, nats.ErrKeyNotFound) {
		return err
	}
	if err == nil {
		_ = json.Unmarshal(entry.Value(), &document)
	}

	topics, ok := document["topics"].(map[string]any)
	if !ok {
		topics = map[string]any{}
		document["topics"] = topics
	}

	topic := resolvedChannelAddress(resolved.Channel, resolved.Device)
	topics[topic] = map[string]any{
		"topic": topic,
		"payload": map[string]any{
			"values":               telemetry.FinalValues,
			"ingestion_message_id": message.ID,
			"telemetry_log_id":     telemetry.ID,
			"status":               status,
			"recorded_at":          telemetryRecordedAt(telemetry),
		},
		"stored_at": time.Now().UTC().Format(time.RFC3339),
	}

	encoded, err := json.Marshal(document)
	if err != nil {
		return err
	}

	_, err = kv.Put(resolved.Device.UUID, encoded)
	return err
}

func (p *NATSEventPublisher) PublishAnalytics(_ context.Context, telemetry PersistedTelemetry, message Message, resolved ResolvedTopic) error {
	if telemetry.ProcessingState == "processed" {
		if !p.config.PublishAnalytics {
			return nil
		}

		return p.publishSideEffect(p.buildTelemetrySubject(resolved.Device, resolved.Channel), map[string]any{
			"ingestion_message_id": message.ID,
			"organization_id":      resolved.Device.OrganizationID,
			"device_uuid":          resolved.Device.UUID,
			"device_external_id":   resolved.Device.ExternalID,
			"channel_key":          resolved.Channel.Key,
			"channel_address":      resolved.Channel.Address,
			"recorded_at":          time.Now().UTC().Format(time.RFC3339),
			"values":               telemetry.FinalValues,
		})
	}

	if telemetry.ProcessingState != "invalid" || !resolved.Device.IsActive || !p.config.PublishInvalidEvents {
		return nil
	}

	return p.publishSideEffect(p.buildInvalidSubject(resolved.Device, invalidReason(telemetry.ValidationErrors)), map[string]any{
		"ingestion_message_id": message.ID,
		"organization_id":      resolved.Device.OrganizationID,
		"device_uuid":          resolved.Device.UUID,
		"device_external_id":   resolved.Device.ExternalID,
		"channel_key":          resolved.Channel.Key,
		"channel_address":      resolved.Channel.Address,
		"recorded_at":          time.Now().UTC().Format(time.RFC3339),
		"errors":               telemetry.ValidationErrors,
	})
}

func (p *NATSEventPublisher) publish(subject string, payload map[string]any) error {
	encoded, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	return p.nc.Publish(subject, encoded)
}

func (p *NATSEventPublisher) publishSideEffect(subject string, payload map[string]any) error {
	encoded, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	return p.sideEffectsNC.Publish(subject, encoded)
}

func (p *NATSEventPublisher) hotStateBucket() (nats.KeyValue, error) {
	js, err := p.sideEffectsNC.JetStream()
	if err != nil {
		return nil, err
	}

	bucket := strings.TrimSpace(p.config.HotStateBucket)
	if bucket == "" {
		bucket = "device-states"
	}

	kv, err := js.KeyValue(bucket)
	if errors.Is(err, nats.ErrBucketNotFound) {
		return js.CreateKeyValue(&nats.KeyValueConfig{Bucket: bucket})
	}

	return kv, err
}

func (p *NATSEventPublisher) buildTelemetrySubject(device Device, channel Channel) string {
	return strings.Join([]string{
		strings.Trim(p.config.AnalyticsPrefix, "."),
		sanitizeSubjectToken(p.config.SubjectEnvironment),
		sanitizeSubjectToken(int64SubjectToken(device.OrganizationID)),
		sanitizeSubjectToken(device.UUID),
		sanitizeSubjectToken(channel.Key),
	}, ".")
}

func (p *NATSEventPublisher) buildInvalidSubject(device Device, reason string) string {
	return strings.Join([]string{
		strings.Trim(p.config.InvalidPrefix, "."),
		sanitizeSubjectToken(p.config.SubjectEnvironment),
		sanitizeSubjectToken(int64SubjectToken(device.OrganizationID)),
		sanitizeSubjectToken(reason),
	}, ".")
}

func telemetryRecordedAt(telemetry PersistedTelemetry) string {
	if telemetry.RecordedAt.IsZero() {
		return time.Now().UTC().Format(time.RFC3339)
	}

	return telemetry.RecordedAt.UTC().Format(time.RFC3339)
}

func invalidReason(validationErrors map[string]any) string {
	for _, value := range validationErrors {
		errorMap, ok := value.(map[string]any)
		if !ok {
			continue
		}
		if isCritical, ok := errorMap["is_critical"].(bool); ok && isCritical {
			return "critical_validation"
		}
	}

	return "validation"
}

func sanitizeSubjectToken(value string) string {
	normalized := strings.ToLower(strings.TrimSpace(value))
	normalized = regexp.MustCompile(`[^a-z0-9_-]+`).ReplaceAllString(normalized, "-")
	normalized = strings.Trim(normalized, "-")
	if normalized == "" {
		return "unknown"
	}

	return normalized
}

func int64SubjectToken(value int64) string {
	return strconv.FormatInt(value, 10)
}
