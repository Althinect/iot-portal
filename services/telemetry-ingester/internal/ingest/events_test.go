package ingest

import (
	"context"
	"encoding/json"
	"testing"

	"github.com/tharindarodrigo/iot-portal/services/telemetry-ingester/internal/config"
)

func TestIngestionEventsUseSideEffectsPublisher(t *testing.T) {
	publishedSubjects := []string{}
	publisher := &NATSEventPublisher{
		config: config.Config{
			IncomingEventSubject:  "iot.v1.ingestion.incoming",
			PersistedEventSubject: "iot.v1.ingestion.persisted",
		},
		publishMessage: func(subject string, data []byte) error {
			var payload map[string]any
			if err := json.Unmarshal(data, &payload); err != nil {
				t.Fatalf("published payload is not valid json: %v", err)
			}
			publishedSubjects = append(publishedSubjects, subject)
			return nil
		},
	}

	err := publisher.PublishIncoming(context.Background(), Envelope{
		MQTTTopic: "devices/meter-01/telemetry",
		Payload:   map[string]any{"voltage": 239.5},
	})
	if err != nil {
		t.Fatalf("publish incoming: %v", err)
	}

	err = publisher.PublishPersisted(context.Background(), PersistedTelemetry{ID: "telemetry-1"}, Message{}, ResolvedTopic{})
	if err != nil {
		t.Fatalf("publish persisted: %v", err)
	}

	if len(publishedSubjects) != 2 {
		t.Fatalf("expected two ingestion side-effect events, got %d", len(publishedSubjects))
	}
	if publishedSubjects[0] != "iot.v1.ingestion.incoming" {
		t.Fatalf("unexpected incoming subject: %s", publishedSubjects[0])
	}
	if publishedSubjects[1] != "iot.v1.ingestion.persisted" {
		t.Fatalf("unexpected persisted subject: %s", publishedSubjects[1])
	}
}

func TestAnalyticsSubjectsMatchLaravelContract(t *testing.T) {
	publisher := NewNATSEventPublisher(nil, nil, config.Config{
		SubjectEnvironment: "Production",
		AnalyticsPrefix:    "iot.v1.analytics",
		InvalidPrefix:      "iot.v1.invalid",
	})

	device := Device{
		OrganizationID: 123,
		UUID:           "Device UUID",
	}
	channel := Channel{Key: "Temperature Sensor"}

	telemetrySubject := publisher.buildTelemetrySubject(device, channel)
	if telemetrySubject != "iot.v1.analytics.production.123.device-uuid.temperature-sensor" {
		t.Fatalf("unexpected telemetry subject: %s", telemetrySubject)
	}

	invalidSubject := publisher.buildInvalidSubject(device, "critical_validation")
	if invalidSubject != "iot.v1.invalid.production.123.critical_validation" {
		t.Fatalf("unexpected invalid subject: %s", invalidSubject)
	}
}

func TestInvalidReasonDetectsCriticalValidationErrors(t *testing.T) {
	reason := invalidReason(map[string]any{
		"temp_c": map[string]any{
			"error_code":  "invalid_temp_c",
			"is_critical": true,
		},
	})

	if reason != "critical_validation" {
		t.Fatalf("expected critical_validation, got %s", reason)
	}

	reason = invalidReason(map[string]any{
		"humidity": map[string]any{
			"error_code":  "invalid_humidity",
			"is_critical": false,
		},
	})

	if reason != "validation" {
		t.Fatalf("expected validation, got %s", reason)
	}
}
