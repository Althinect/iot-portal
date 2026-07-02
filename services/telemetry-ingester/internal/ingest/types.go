package ingest

import "time"

const (
	StatusQueued           = "queued"
	StatusCompleted        = "completed"
	StatusFailedValidation = "failed_validation"
	StatusInactiveSkipped  = "inactive_skipped"
	StatusFailedTerminal   = "failed_terminal"
	StatusDuplicate        = "duplicate"

	StageIngress = "ingress"
	StagePersist = "persist"
)

type Envelope struct {
	SourceSubject    string
	MQTTTopic        string
	Payload          map[string]any
	DeviceUUID       string
	DeviceExternalID string
	MessageID        string
	ReceivedAt       time.Time
}

type Device struct {
	ID               int64
	OrganizationID   int64
	ProfileVersionID int64
	UUID             string
	ExternalID       string
	IsActive         bool
	BaseTopic        string
}

type Channel struct {
	ID        int64
	VersionID int64
	Key       string
	Address   string
	Direction string
	Transport string
	Retain    bool
	Sequence  int
}

type Parameter struct {
	ID                  int64
	ChannelID           int64
	Key                 string
	JSONPath            string
	Type                string
	Required            bool
	IsCritical          bool
	IsActive            bool
	Sequence            int
	ValidationErrorCode string
	ValidationRules     map[string]any
	MutationExpression  any
}

type DerivedParameter struct {
	Key          string
	Expression   any
	Dependencies []string
}

type ResolvedTopic struct {
	Device     Device
	Channel    Channel
	Parameters []Parameter
	Derived    []DerivedParameter
}

type Binding struct {
	Device         Device
	Channel        Channel
	ParameterKey   string
	TargetJSONPath string
	ParameterType  string
	SourceJSONPath string
	Metadata       map[string]any
	Sequence       int
}

type Message struct {
	ID               string
	DeduplicationKey string
	Status           string
}

type ValidationResult struct {
	ExtractedValues  map[string]any
	ValidationErrors map[string]any
	Status           string
	IsInvalid        bool
}

type PersistedTelemetry struct {
	ID               string
	ProcessingState  string
	ValidationStatus string
	FinalValues      map[string]any
	ValidationErrors map[string]any
	RecordedAt       time.Time
}

type StageLog struct {
	MessageID      string
	Stage          string
	Status         string
	DurationMS     *int64
	InputSnapshot  map[string]any
	OutputSnapshot map[string]any
	Errors         map[string]any
}
