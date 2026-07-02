package config

import (
	"fmt"
	"net/url"
	"os"
	"strconv"
	"strings"
)

type Config struct {
	Enabled               bool
	Driver                string
	DatabaseURL           string
	NATSURL               string
	SideEffectsNATSURL    string
	Subjects              []string
	RegistryTTLSeconds    int
	StageLogMode          string
	StageLogSampleRate    float64
	CaptureStageSnapshots bool
	SubjectEnvironment    string
	AnalyticsPrefix       string
	InvalidPrefix         string
	PublishAnalytics      bool
	PublishInvalidEvents  bool
	WriteHotState         bool
	HotStateBucket        string
	IncomingEventSubject  string
	PersistedEventSubject string
}

func FromEnv() Config {
	host := envString("INGESTION_NATS_HOST", envString("IOT_NATS_HOST", "127.0.0.1"))
	port := envString("INGESTION_NATS_PORT", envString("IOT_NATS_PORT", "4222"))
	sideEffectsHost := envString("INGESTION_SIDE_EFFECTS_NATS_HOST", envString("IOT_NATS_HOST", host))
	sideEffectsPort := envString("INGESTION_SIDE_EFFECTS_NATS_PORT", envString("IOT_NATS_PORT", port))

	return Config{
		Enabled:               envBool("INGESTION_PIPELINE_ENABLED", true),
		Driver:                envString("INGESTION_PIPELINE_DRIVER", "go"),
		DatabaseURL:           databaseURL(),
		NATSURL:               envString("INGESTION_NATS_URL", "nats://"+host+":"+port),
		SideEffectsNATSURL:    envString("INGESTION_SIDE_EFFECTS_NATS_URL", "nats://"+sideEffectsHost+":"+sideEffectsPort),
		Subjects:              envList("INGESTION_NATS_SUBJECT", "devices.*.telemetry,devices.*.*.telemetry,devices.*.*.*.telemetry,migration.source.imoni.*.*.telemetry,migration.source.egravity.*.telemetry"),
		RegistryTTLSeconds:    envInt("INGESTION_REGISTRY_TTL_SECONDS", 30),
		StageLogMode:          envString("INGESTION_STAGE_LOG_MODE", "failures"),
		StageLogSampleRate:    envFloat("INGESTION_STAGE_LOG_SAMPLE_RATE", 0),
		CaptureStageSnapshots: envBool("INGESTION_CAPTURE_STAGE_SNAPSHOTS", true),
		SubjectEnvironment:    envString("INGESTION_SUBJECT_ENVIRONMENT", envString("APP_ENV", "production")),
		AnalyticsPrefix:       envString("INGESTION_NATS_ANALYTICS_PREFIX", "iot.v1.analytics"),
		InvalidPrefix:         envString("INGESTION_NATS_INVALID_PREFIX", "iot.v1.invalid"),
		PublishAnalytics:      envBool("INGESTION_PIPELINE_PUBLISH_ANALYTICS", true),
		PublishInvalidEvents:  envBool("INGESTION_PIPELINE_PUBLISH_INVALID", true),
		WriteHotState:         envBool("INGESTION_PIPELINE_WRITE_HOT_STATE", true),
		HotStateBucket:        envString("INGESTION_HOT_STATE_BUCKET", "device-states"),
		IncomingEventSubject:  envString("INGESTION_GO_INCOMING_SUBJECT", "iot.v1.ingestion.incoming"),
		PersistedEventSubject: envString("INGESTION_GO_PERSISTED_SUBJECT", "iot.v1.ingestion.persisted"),
	}
}

func databaseURL() string {
	if value := strings.TrimSpace(os.Getenv("DATABASE_URL")); value != "" {
		return value
	}

	username := envString("DB_USERNAME", "sail")
	password := envString("DB_PASSWORD", "password")
	host := envString("DB_HOST", "127.0.0.1")
	port := envString("DB_PORT", "5432")
	name := envString("DB_DATABASE", "laravel")
	sslMode := envString("DB_SSLMODE", "disable")

	userInfo := url.UserPassword(username, password)
	return fmt.Sprintf("postgres://%s@%s:%s/%s?sslmode=%s", userInfo.String(), host, port, name, sslMode)
}

func envString(key string, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(key)); value != "" {
		return value
	}
	return fallback
}

func envList(key string, fallback string) []string {
	value := envString(key, fallback)
	parts := strings.Split(value, ",")
	subjects := make([]string, 0, len(parts))
	for _, part := range parts {
		if subject := strings.Trim(strings.TrimSpace(part), "'\""); subject != "" {
			subjects = append(subjects, subject)
		}
	}
	return subjects
}

func envBool(key string, fallback bool) bool {
	value := strings.ToLower(strings.TrimSpace(os.Getenv(key)))
	if value == "" {
		return fallback
	}
	return value == "1" || value == "true" || value == "yes" || value == "on"
}

func envInt(key string, fallback int) int {
	value, err := strconv.Atoi(strings.TrimSpace(os.Getenv(key)))
	if err != nil {
		return fallback
	}
	return value
}

func envFloat(key string, fallback float64) float64 {
	value, err := strconv.ParseFloat(strings.TrimSpace(os.Getenv(key)), 64)
	if err != nil {
		return fallback
	}
	return value
}
