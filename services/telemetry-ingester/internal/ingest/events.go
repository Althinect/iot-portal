package ingest

import (
	"context"
	"encoding/json"
	"time"

	"github.com/nats-io/nats.go"
	"github.com/tharindarodrigo/iot-portal/services/telemetry-ingester/internal/config"
)

type NATSEventPublisher struct {
	nc     *nats.Conn
	config config.Config
}

func NewNATSEventPublisher(nc *nats.Conn, cfg config.Config) *NATSEventPublisher {
	return &NATSEventPublisher{nc: nc, config: cfg}
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

func (p *NATSEventPublisher) publish(subject string, payload map[string]any) error {
	encoded, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	return p.nc.Publish(subject, encoded)
}
