package main

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/nats-io/nats.go"
	"github.com/tharindarodrigo/iot-portal/services/telemetry-ingester/internal/config"
	"github.com/tharindarodrigo/iot-portal/services/telemetry-ingester/internal/ingest"
)

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	cfg := config.FromEnv()

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	db, err := pgxpool.New(ctx, cfg.DatabaseURL)
	if err != nil {
		logger.Error("database connection failed", "error", err)
		os.Exit(1)
	}
	defer db.Close()

	nc, err := nats.Connect(cfg.NATSURL, nats.Name("iot-portal-telemetry-ingester"))
	if err != nil {
		logger.Error("nats connection failed", "error", err)
		os.Exit(1)
	}
	defer nc.Drain()

	store := ingest.NewPostgresStore(db, cfg, logger)
	publisher := ingest.NewNATSEventPublisher(nc, cfg)
	pipeline := ingest.NewPipeline(store, publisher, cfg, logger)

	for _, subject := range cfg.Subjects {
		subject := strings.TrimSpace(subject)
		if subject == "" || subject == ">" || subject == "#" {
			continue
		}

		_, err = nc.Subscribe(subject, func(msg *nats.Msg) {
			if shouldIgnoreSubject(msg.Subject, cfg) {
				return
			}

			payload := map[string]any{}
			if err := json.Unmarshal(msg.Data, &payload); err != nil {
				logger.Warn("telemetry payload is not valid json", "subject", msg.Subject, "error", err)
				return
			}

			envelope := ingest.Envelope{
				SourceSubject: msg.Subject,
				MQTTTopic:     strings.ReplaceAll(msg.Subject, ".", "/"),
				Payload:       payload,
				MessageID:     messageID(msg),
				ReceivedAt:    time.Now().UTC(),
			}

			if err := pipeline.Process(ctx, envelope); err != nil && !errors.Is(err, context.Canceled) {
				logger.Error("telemetry ingestion failed", "subject", msg.Subject, "error", err)
			}
		})
		if err != nil {
			logger.Error("nats subscribe failed", "subject", subject, "error", err)
			os.Exit(1)
		}
		logger.Info("subscribed to telemetry subject", "subject", subject)
	}

	<-ctx.Done()
	logger.Info("telemetry ingester stopping")
}

func messageID(msg *nats.Msg) string {
	for _, key := range []string{"Nats-Msg-Id", "Nats-Msg-ID", "Message-Id", "Message-ID"} {
		value := strings.TrimSpace(msg.Header.Get(key))
		if value != "" {
			return value
		}
	}
	return ""
}

func shouldIgnoreSubject(subject string, cfg config.Config) bool {
	if subject == "" {
		return true
	}

	for _, prefix := range []string{"$JS.", "$KV.", "_INBOX.", "_REQS."} {
		if strings.HasPrefix(subject, prefix) {
			return true
		}
	}

	return strings.HasPrefix(subject, cfg.AnalyticsPrefix+".") ||
		strings.HasPrefix(subject, cfg.InvalidPrefix+".") ||
		subject == cfg.IncomingEventSubject ||
		subject == cfg.PersistedEventSubject
}
