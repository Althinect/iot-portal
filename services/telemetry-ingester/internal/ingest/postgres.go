package ingest

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"strings"
	"sync"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/tharindarodrigo/iot-portal/services/telemetry-ingester/internal/config"
)

type PostgresStore struct {
	db          *pgxpool.Pool
	config      config.Config
	logger      *slog.Logger
	mu          sync.RWMutex
	registry    *registry
	refreshedAt time.Time
}

type registry struct {
	topics   map[string]ResolvedTopic
	bindings map[string][]Binding
}

func NewPostgresStore(db *pgxpool.Pool, cfg config.Config, logger *slog.Logger) *PostgresStore {
	return &PostgresStore{db: db, config: cfg, logger: logger}
}

func (s *PostgresStore) ResolveTopic(ctx context.Context, topic string) (*ResolvedTopic, error) {
	registry, err := s.currentRegistry(ctx)
	if err != nil {
		return nil, err
	}
	if resolved, ok := registry.topics[topic]; ok {
		return &resolved, nil
	}
	return nil, nil
}

func (s *PostgresStore) ResolveBindings(ctx context.Context, topic string) ([]Binding, error) {
	registry, err := s.currentRegistry(ctx)
	if err != nil {
		return nil, err
	}
	return registry.bindings[topic], nil
}

func (s *PostgresStore) CreateQueuedMessage(ctx context.Context, envelope Envelope) (Message, bool, error) {
	messageID := NewMessageID()
	deduplicationKey := DeduplicationKey(envelope)
	payload, err := json.Marshal(envelope.Payload)
	if err != nil {
		return Message{}, false, err
	}

	commandTag, err := s.db.Exec(ctx, `
		insert into ingestion_messages
			(id, source_subject, source_protocol, source_message_id, source_deduplication_key, raw_payload, status, received_at, created_at, updated_at)
		values
			($1, $2, 'mqtt', nullif($3, ''), $4, $5, $6, $7, now(), now())
		on conflict (source_deduplication_key) do nothing
	`, messageID, envelope.SourceSubject, envelope.MessageID, deduplicationKey, payload, StatusQueued, envelope.ReceivedAt)
	if err != nil {
		return Message{}, false, err
	}
	if commandTag.RowsAffected() == 1 {
		return Message{ID: messageID, DeduplicationKey: deduplicationKey, Status: StatusQueued}, true, nil
	}

	message := Message{}
	if err := s.db.QueryRow(ctx, `
		select id, source_deduplication_key, status
		from ingestion_messages
		where source_deduplication_key = $1
	`, deduplicationKey).Scan(&message.ID, &message.DeduplicationKey, &message.Status); err != nil {
		return Message{}, false, err
	}

	if message.Status != StatusDuplicate {
		_, err = s.db.Exec(ctx, `
			update ingestion_messages
			set status = $2, updated_at = now()
			where id = $1 and status <> $2
		`, message.ID, StatusDuplicate)
		if err != nil {
			return Message{}, false, err
		}
		message.Status = StatusDuplicate
	}

	return message, false, nil
}

func (s *PostgresStore) LogStage(ctx context.Context, log StageLog) error {
	input, _ := json.Marshal(log.InputSnapshot)
	output, _ := json.Marshal(log.OutputSnapshot)
	errorsJSON, _ := json.Marshal(log.Errors)

	_, err := s.db.Exec(ctx, `
		insert into ingestion_stage_logs
			(ingestion_message_id, stage, status, duration_ms, input_snapshot, output_snapshot, errors, created_at)
		values
			($1, $2, $3, $4, $5, $6, $7, now())
	`, log.MessageID, log.Stage, log.Status, log.DurationMS, nullableJSON(input, log.InputSnapshot), nullableJSON(output, log.OutputSnapshot), nullableJSON(errorsJSON, log.Errors))
	return err
}

func (s *PostgresStore) FinalizeMessage(ctx context.Context, messageID string, values map[string]any) error {
	errorSummary, _ := json.Marshal(values["error_summary"])
	_, err := s.db.Exec(ctx, `
		update ingestion_messages
		set organization_id = $2,
		    device_id = $3,
		    device_profile_version_id = $4,
		    device_channel_id = $5,
		    status = $6,
		    error_summary = $7,
		    processed_at = coalesce($8, now()),
		    updated_at = now()
		where id = $1
	`, messageID, values["organization_id"], values["device_id"], values["device_profile_version_id"], values["device_channel_id"], values["status"], nullableJSON(errorSummary, values["error_summary"]), values["processed_at"])
	return err
}

func (s *PostgresStore) PersistTelemetry(
	ctx context.Context,
	message Message,
	resolved ResolvedTopic,
	rawPayload map[string]any,
	finalValues map[string]any,
	mutatedValues map[string]any,
	validationErrors map[string]any,
	validationStatus string,
	processingState string,
	receivedAt time.Time,
) (PersistedTelemetry, error) {
	id := uuid.NewString()
	raw := nonNullJSON(rawPayload)
	final := nonNullJSON(finalValues)
	mutated, _ := json.Marshal(mutatedValues)
	errorsJSON, _ := json.Marshal(validationErrors)

	_, err := s.db.Exec(ctx, `
		insert into device_telemetry_logs
			(id, device_id, device_profile_version_id, device_channel_id, ingestion_message_id,
			 validation_status, processing_state, raw_payload, validation_errors, mutated_values,
			 transformed_values, recorded_at, received_at, created_at, updated_at)
		values
			($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $12, now(), now())
	`, id, resolved.Device.ID, resolved.Device.ProfileVersionID, resolved.Channel.ID, message.ID, validationStatus, processingState, raw, nullableJSON(errorsJSON, validationErrors), nullableJSON(mutated, mutatedValues), final, receivedAt)
	if err != nil {
		return PersistedTelemetry{}, err
	}

	return PersistedTelemetry{
		ID:               id,
		ProcessingState:  processingState,
		ValidationStatus: validationStatus,
		FinalValues:      finalValues,
		ValidationErrors: validationErrors,
	}, nil
}

func nonNullJSON(values map[string]any) []byte {
	if values == nil {
		return []byte("{}")
	}

	encoded, err := json.Marshal(values)
	if err != nil || string(encoded) == "null" {
		return []byte("{}")
	}

	return encoded
}

func (s *PostgresStore) MarkOnline(ctx context.Context, deviceID int64, seenAt time.Time) error {
	_, err := s.db.Exec(ctx, `
		update devices
		set connection_state = 'online',
		    last_seen_at = greatest(coalesce(last_seen_at, $2), $2),
		    updated_at = now()
		where id = $1
	`, deviceID, seenAt)
	return err
}

func (s *PostgresStore) currentRegistry(ctx context.Context) (*registry, error) {
	s.mu.RLock()
	if s.registry != nil && time.Since(s.refreshedAt) < time.Duration(s.config.RegistryTTLSeconds)*time.Second {
		defer s.mu.RUnlock()
		return s.registry, nil
	}
	s.mu.RUnlock()

	s.mu.Lock()
	defer s.mu.Unlock()
	if s.registry != nil && time.Since(s.refreshedAt) < time.Duration(s.config.RegistryTTLSeconds)*time.Second {
		return s.registry, nil
	}

	registry, err := s.loadRegistry(ctx)
	if err != nil {
		return nil, err
	}
	s.registry = registry
	s.refreshedAt = time.Now()
	return registry, nil
}

func (s *PostgresStore) loadRegistry(ctx context.Context) (*registry, error) {
	registry := &registry{
		topics:   map[string]ResolvedTopic{},
		bindings: map[string][]Binding{},
	}

	devices, err := s.loadDevices(ctx)
	if err != nil {
		return nil, err
	}
	channels, err := s.loadChannels(ctx)
	if err != nil {
		return nil, err
	}
	parameters, err := s.loadParameters(ctx)
	if err != nil {
		return nil, err
	}
	derived, err := s.loadDerived(ctx)
	if err != nil {
		return nil, err
	}

	for _, device := range devices {
		for _, channel := range channels[device.ProfileVersionID] {
			if channel.Direction != "publish" || channel.Transport != "mqtt" {
				continue
			}
			resolved := ResolvedTopic{
				Device:     device,
				Channel:    channel,
				Parameters: parameters[channel.ID],
				Derived:    derived[device.ProfileVersionID],
			}
			topic := resolvedChannelAddress(channel, device)
			registry.topics[topic] = resolved
		}
	}

	bindings, err := s.loadBindings(ctx, devices, channels, parameters)
	if err != nil {
		return nil, err
	}
	for _, binding := range bindings {
		sourceTopic := binding.Metadata["_source_topic"]
		if topic, ok := sourceTopic.(string); ok && strings.TrimSpace(topic) != "" {
			registry.bindings[topic] = append(registry.bindings[topic], binding)
		}
	}

	s.logger.Info("telemetry registry refreshed", "topics", len(registry.topics), "binding_topics", len(registry.bindings))
	return registry, nil
}

func (s *PostgresStore) loadDevices(ctx context.Context) (map[int64]Device, error) {
	rows, err := s.db.Query(ctx, `
		select d.id, d.organization_id, d.device_profile_version_id, d.uuid::text, coalesce(d.external_id, ''),
		       d.is_active, coalesce(dpv.protocol_config, '{}'::jsonb)
		from devices d
		join device_profile_versions dpv on dpv.id = d.device_profile_version_id
		where d.deleted_at is null
	`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	devices := map[int64]Device{}
	for rows.Next() {
		var device Device
		var protocolConfig []byte
		if err := rows.Scan(&device.ID, &device.OrganizationID, &device.ProfileVersionID, &device.UUID, &device.ExternalID, &device.IsActive, &protocolConfig); err != nil {
			return nil, err
		}
		device.BaseTopic = baseTopic(protocolConfig)
		devices[device.ID] = device
	}
	return devices, rows.Err()
}

func (s *PostgresStore) loadChannels(ctx context.Context) (map[int64][]Channel, error) {
	rows, err := s.db.Query(ctx, `
		select id, device_profile_version_id, key, address, direction, transport, retain, sequence
		from device_channels
		order by sequence, id
	`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	channels := map[int64][]Channel{}
	for rows.Next() {
		var channel Channel
		if err := rows.Scan(&channel.ID, &channel.VersionID, &channel.Key, &channel.Address, &channel.Direction, &channel.Transport, &channel.Retain, &channel.Sequence); err != nil {
			return nil, err
		}
		channels[channel.VersionID] = append(channels[channel.VersionID], channel)
	}
	return channels, rows.Err()
}

func (s *PostgresStore) loadParameters(ctx context.Context) (map[int64][]Parameter, error) {
	rows, err := s.db.Query(ctx, `
		select id, device_channel_id, key, json_path, type, required, is_critical, is_active,
		       sequence, coalesce(validation_error_code, ''), validation_rules, mutation_expression
		from profile_parameter_definitions
		order by sequence, id
	`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	parameters := map[int64][]Parameter{}
	for rows.Next() {
		parameter := Parameter{}
		var validationRules, mutationExpression []byte
		if err := rows.Scan(&parameter.ID, &parameter.ChannelID, &parameter.Key, &parameter.JSONPath, &parameter.Type, &parameter.Required, &parameter.IsCritical, &parameter.IsActive, &parameter.Sequence, &parameter.ValidationErrorCode, &validationRules, &mutationExpression); err != nil {
			return nil, err
		}
		parameter.ValidationRules = decodeMap(validationRules)
		parameter.MutationExpression = decodeAny(mutationExpression)
		parameters[parameter.ChannelID] = append(parameters[parameter.ChannelID], parameter)
	}
	return parameters, rows.Err()
}

func (s *PostgresStore) loadDerived(ctx context.Context) (map[int64][]DerivedParameter, error) {
	rows, err := s.db.Query(ctx, `
		select device_profile_version_id, key, expression, dependencies
		from profile_derived_parameter_definitions
		order by id
	`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	derived := map[int64][]DerivedParameter{}
	for rows.Next() {
		var versionID int64
		var definition DerivedParameter
		var expression, dependencies []byte
		if err := rows.Scan(&versionID, &definition.Key, &expression, &dependencies); err != nil {
			return nil, err
		}
		definition.Expression = decodeAny(expression)
		definition.Dependencies = decodeStringList(dependencies)
		derived[versionID] = append(derived[versionID], definition)
	}
	return derived, rows.Err()
}

func (s *PostgresStore) loadBindings(ctx context.Context, devices map[int64]Device, channels map[int64][]Channel, parameters map[int64][]Parameter) ([]Binding, error) {
	rows, err := s.db.Query(ctx, `
		select device_id, device_channel_id, parameter_key, source_topic, source_json_path, coalesce(metadata, '{}'::jsonb), sequence
		from device_signal_bindings
		where is_active = true
		order by source_topic, sequence
	`)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	defer rows.Close()

	bindings := []Binding{}
	for rows.Next() {
		var deviceID, channelID int64
		var sourceTopic string
		binding := Binding{}
		var metadata []byte
		if err := rows.Scan(&deviceID, &channelID, &binding.ParameterKey, &sourceTopic, &binding.SourceJSONPath, &metadata, &binding.Sequence); err != nil {
			return nil, err
		}
		device, ok := devices[deviceID]
		if !ok {
			continue
		}
		channel, ok := findChannel(channels[device.ProfileVersionID], channelID)
		if !ok {
			continue
		}
		binding.Device = device
		binding.Channel = channel
		if parameter, ok := findParameter(parameters[channel.ID], binding.ParameterKey); ok {
			binding.TargetJSONPath = parameter.JSONPath
			binding.ParameterType = parameter.Type
		} else {
			continue
		}
		binding.Metadata = decodeMap(metadata)
		binding.Metadata["_source_topic"] = sourceTopic
		bindings = append(bindings, binding)
	}
	return bindings, rows.Err()
}

func findChannel(channels []Channel, id int64) (Channel, bool) {
	for _, channel := range channels {
		if channel.ID == id {
			return channel, true
		}
	}
	return Channel{}, false
}

func findParameter(parameters []Parameter, key string) (Parameter, bool) {
	for _, parameter := range parameters {
		if parameter.Key == key {
			return parameter, true
		}
	}
	return Parameter{}, false
}

func baseTopic(raw []byte) string {
	config := decodeMap(raw)
	if value, ok := config["base_topic"].(string); ok && strings.TrimSpace(value) != "" {
		return value
	}
	return "device"
}

func decodeMap(raw []byte) map[string]any {
	if len(raw) == 0 {
		return map[string]any{}
	}
	decoded := map[string]any{}
	_ = json.Unmarshal(raw, &decoded)
	return decoded
}

func decodeAny(raw []byte) any {
	if len(raw) == 0 {
		return nil
	}
	var decoded any
	_ = json.Unmarshal(raw, &decoded)
	return decoded
}

func decodeStringList(raw []byte) []string {
	if len(raw) == 0 {
		return nil
	}
	var stringsList []string
	if err := json.Unmarshal(raw, &stringsList); err == nil {
		return stringsList
	}
	var anyList []any
	if err := json.Unmarshal(raw, &anyList); err != nil {
		return nil
	}
	result := make([]string, 0, len(anyList))
	for _, item := range anyList {
		if value, ok := item.(string); ok {
			result = append(result, value)
		}
	}
	return result
}

func nullableJSON(encoded []byte, value any) any {
	if value == nil {
		return nil
	}
	switch typed := value.(type) {
	case map[string]any:
		if len(typed) == 0 {
			return nil
		}
	}
	if string(encoded) == "null" {
		return nil
	}
	return encoded
}
