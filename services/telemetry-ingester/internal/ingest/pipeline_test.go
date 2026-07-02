package ingest

import (
	"context"
	"io"
	"log/slog"
	"testing"
	"time"

	"github.com/tharindarodrigo/iot-portal/services/telemetry-ingester/internal/config"
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

func TestPipelinePublishesDataPlaneSideEffectsAfterPersistence(t *testing.T) {
	store := &pipelineFakeStore{
		resolved: &ResolvedTopic{
			Device: Device{
				ID:               10,
				OrganizationID:   20,
				ProfileVersionID: 30,
				UUID:             "device-uuid",
				ExternalID:       "device-external",
				IsActive:         true,
				BaseTopic:        "devices",
			},
			Channel: Channel{
				ID:      40,
				Key:     "temperature",
				Address: "telemetry",
			},
			Parameters: []Parameter{
				{
					Key:        "temp_c",
					JSONPath:   "temperature",
					Type:       "decimal",
					Required:   true,
					IsActive:   true,
					IsCritical: true,
				},
			},
		},
	}
	publisher := &pipelineFakePublisher{}
	pipeline := NewPipeline(store, publisher, config.Config{
		Enabled: true,
		Driver:  "go",
	}, slog.New(slog.NewTextHandler(io.Discard, nil)))

	receivedAt := time.Date(2026, 7, 2, 10, 15, 0, 0, time.UTC)
	err := pipeline.Process(context.Background(), Envelope{
		SourceSubject: "devices.device-external.telemetry",
		MQTTTopic:     "devices/device-external/telemetry",
		Payload:       map[string]any{"temperature": 25.5},
		ReceivedAt:    receivedAt,
	})

	if err != nil {
		t.Fatalf("expected pipeline process without error, got %v", err)
	}
	if publisher.hotStateStatuses[0] != StatusCompleted {
		t.Fatalf("expected hot state to receive completed status, got %s", publisher.hotStateStatuses[0])
	}
	if len(publisher.analytics) != 1 {
		t.Fatalf("expected analytics side effect once, got %d", len(publisher.analytics))
	}
	if len(publisher.persisted) != 1 {
		t.Fatalf("expected persisted event once, got %d", len(publisher.persisted))
	}
	if !store.markedOnline {
		t.Fatal("expected device marked online before side effects")
	}
}

type pipelineFakeStore struct {
	resolved     *ResolvedTopic
	markedOnline bool
}

func (s *pipelineFakeStore) ResolveTopic(context.Context, string) (*ResolvedTopic, error) {
	return s.resolved, nil
}

func (s *pipelineFakeStore) ResolveBindings(context.Context, string) ([]Binding, error) {
	return nil, nil
}

func (s *pipelineFakeStore) CreateQueuedMessage(context.Context, Envelope) (Message, bool, error) {
	return Message{ID: "message-1", Status: StatusQueued}, true, nil
}

func (s *pipelineFakeStore) LogStage(context.Context, StageLog) error {
	return nil
}

func (s *pipelineFakeStore) FinalizeMessage(context.Context, string, map[string]any) error {
	return nil
}

func (s *pipelineFakeStore) PersistTelemetry(_ context.Context, _ Message, _ ResolvedTopic, _ map[string]any, finalValues map[string]any, _ map[string]any, validationErrors map[string]any, validationStatus string, processingState string, recordedAt time.Time) (PersistedTelemetry, error) {
	return PersistedTelemetry{
		ID:               "telemetry-1",
		ProcessingState:  processingState,
		ValidationStatus: validationStatus,
		FinalValues:      finalValues,
		ValidationErrors: validationErrors,
		RecordedAt:       recordedAt,
	}, nil
}

func (s *pipelineFakeStore) MarkOnline(context.Context, int64, time.Time) error {
	s.markedOnline = true

	return nil
}

type pipelineFakePublisher struct {
	analytics        []string
	persisted        []string
	hotStateStatuses []string
}

func (p *pipelineFakePublisher) PublishIncoming(context.Context, Envelope) error {
	return nil
}

func (p *pipelineFakePublisher) PublishPersisted(_ context.Context, telemetry PersistedTelemetry, _ Message, _ ResolvedTopic) error {
	p.persisted = append(p.persisted, telemetry.ID)

	return nil
}

func (p *pipelineFakePublisher) PublishHotState(_ context.Context, telemetry PersistedTelemetry, _ Message, _ ResolvedTopic, status string) error {
	p.hotStateStatuses = append(p.hotStateStatuses, status)

	return nil
}

func (p *pipelineFakePublisher) PublishAnalytics(_ context.Context, telemetry PersistedTelemetry, _ Message, _ ResolvedTopic) error {
	p.analytics = append(p.analytics, telemetry.ID)

	return nil
}
