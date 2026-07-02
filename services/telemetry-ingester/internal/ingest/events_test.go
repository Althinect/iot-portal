package ingest

import (
	"testing"

	"github.com/tharindarodrigo/iot-portal/services/telemetry-ingester/internal/config"
)

func TestAnalyticsSubjectsMatchLaravelContract(t *testing.T) {
	publisher := NewNATSEventPublisher(nil, config.Config{
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
