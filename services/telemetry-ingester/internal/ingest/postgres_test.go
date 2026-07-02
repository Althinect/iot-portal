package ingest

import (
	"bytes"
	"log/slog"
	"strings"
	"testing"
)

func TestLogRegistryRefreshOnlyUsesInfoWhenCountsChange(t *testing.T) {
	var buffer bytes.Buffer
	logger := slog.New(slog.NewTextHandler(&buffer, &slog.HandlerOptions{Level: slog.LevelDebug}))

	previous := &registry{
		topics:   map[string]ResolvedTopic{"devices/a/telemetry": {}},
		bindings: map[string][]Binding{"migration/source": {{}}},
	}
	unchanged := &registry{
		topics:   map[string]ResolvedTopic{"devices/b/telemetry": {}},
		bindings: map[string][]Binding{"migration/other": {{}}},
	}
	changed := &registry{
		topics: map[string]ResolvedTopic{
			"devices/a/telemetry": {},
			"devices/b/telemetry": {},
		},
		bindings: map[string][]Binding{"migration/source": {{}}},
	}

	logRegistryRefresh(logger, nil, previous)
	logRegistryRefresh(logger, previous, unchanged)
	logRegistryRefresh(logger, previous, changed)

	output := buffer.String()

	if strings.Count(output, "level=INFO") != 2 {
		t.Fatalf("expected first and changed refreshes to log at info level, got %s", output)
	}

	if strings.Count(output, "level=DEBUG") != 1 {
		t.Fatalf("expected unchanged refresh to log at debug level, got %s", output)
	}
}
