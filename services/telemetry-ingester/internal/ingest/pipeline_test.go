package ingest

import (
	"testing"
	"time"
)

func TestDeduplicationKeyPrefersSourceMessageID(t *testing.T) {
	receivedAt := time.Date(2026, 7, 1, 10, 0, 0, 0, time.UTC)
	first := Envelope{SourceSubject: "devices.sensor.telemetry", MessageID: "abc", Payload: map[string]any{"temp": 20}, ReceivedAt: receivedAt}
	second := Envelope{SourceSubject: "devices.sensor.telemetry", MessageID: "abc", Payload: map[string]any{"temp": 99}, ReceivedAt: receivedAt.Add(time.Hour)}

	if DeduplicationKey(first) != DeduplicationKey(second) {
		t.Fatal("expected message id based dedupe key to ignore payload and timestamp changes")
	}
}

func TestNonNullJSONDefaultsNilMapsToObject(t *testing.T) {
	if string(nonNullJSON(nil)) != "{}" {
		t.Fatal("expected nil maps to encode as an empty JSON object")
	}

	if string(nonNullJSON(map[string]any{"value": 10})) != `{"value":10}` {
		t.Fatal("expected populated maps to encode normally")
	}
}

func TestValidateMutateDerivePayload(t *testing.T) {
	parameters := []Parameter{
		{
			Key:                "temp_c",
			JSONPath:           "temperature",
			Type:               "decimal",
			Required:           true,
			IsCritical:         true,
			IsActive:           true,
			ValidationRules:    map[string]any{"min": 0.0, "max": 100.0},
			MutationExpression: map[string]any{"+": []any{map[string]any{"var": "val"}, 1.0}},
		},
		{
			Key:        "online",
			JSONPath:   "online",
			Type:       "boolean",
			Required:   true,
			IsCritical: true,
			IsActive:   true,
		},
	}

	validation := validatePayload(map[string]any{"temperature": 24.5, "online": true}, parameters)
	if validation.Status != "valid" {
		t.Fatalf("expected valid payload, got %s", validation.Status)
	}

	mutated := mutateValues(validation.ExtractedValues, parameters)
	finalValues := deriveValues(mutated, []DerivedParameter{{
		Key:          "temp_f",
		Dependencies: []string{"temp_c"},
		Expression:   map[string]any{"+": []any{map[string]any{"*": []any{map[string]any{"var": "temp_c"}, 1.8}}, 32.0}},
	}})

	if finalValues["temp_c"] != 25.5 {
		t.Fatalf("expected mutated temp_c 25.5, got %#v", finalValues["temp_c"])
	}
	if finalValues["temp_f"] != 77.9 {
		t.Fatalf("expected derived temp_f 77.9, got %#v", finalValues["temp_f"])
	}
}

func TestValidatePayloadFailsCriticalMissingValue(t *testing.T) {
	validation := validatePayload(map[string]any{}, []Parameter{{
		Key:        "temp_c",
		JSONPath:   "temp_c",
		Type:       "decimal",
		Required:   true,
		IsCritical: true,
		IsActive:   true,
	}})

	if !validation.IsInvalid || validation.Status != "invalid" {
		t.Fatalf("expected invalid critical validation, got %#v", validation)
	}
	if _, ok := validation.ValidationErrors["temp_c"]; !ok {
		t.Fatal("expected temp_c validation error")
	}
}

func TestResolvedChannelAddressUsesProfileBaseTopic(t *testing.T) {
	topic := resolvedChannelAddress(
		Channel{Address: "telemetry"},
		Device{UUID: "uuid-1", ExternalID: "sensor-01", BaseTopic: "devices"},
	)

	if topic != "devices/sensor-01/telemetry" {
		t.Fatalf("unexpected topic: %s", topic)
	}
}
