# Data Ingestion Module - Architecture

## Runtime Model

The telemetry hot path now runs in Go. Laravel remains the control plane and side-effect layer.

```mermaid
flowchart TB
    subgraph External["External / Production Traffic"]
        Prod["Production telemetry forwarder"]
        Tunnel["Cloudflare tunnel<br/>/migration/legacy-ingest"]
    end

    subgraph Local["Local Docker Stack"]
        NodeRED["Node-RED migration adapters<br/>HTTP -> MQTT normalization"]
        EMQX["EMQX<br/>MQTT + NATS bridge<br/>:1883 / :4222"]
        Go["Go telemetry-ingester<br/>services/telemetry-ingester"]
        Bridge["Laravel bridge<br/>php artisan ingestion:consume-go-events"]
        Laravel["Laravel admin app<br/>Filament + domain services"]
        Horizon["Horizon workers<br/>side-effect queues"]
        Reverb["Reverb<br/>realtime dashboard events"]
    end

    subgraph Data["Data Stores"]
        Postgres[("Postgres / TimescaleDB<br/>profiles, devices, telemetry")]
        Redis[("Redis<br/>queues, cache, Horizon")]
        NatsKV[("NATS JetStream / KV<br/>hot device state")]
    end

    Prod -->|HTTPS POST| Tunnel
    Tunnel -->|forwards| NodeRED
    NodeRED -->|MQTT publish| EMQX
    EMQX -->|NATS subscribe<br/>telemetry subjects| Go
    Go -->|read profile/device registry<br/>write ingestion + telemetry rows| Postgres
    Go -->|publish internal events| EMQX
    EMQX -->|iot.v1.ingestion.*| Bridge
    Bridge -->|TelemetryIncoming<br/>TelemetryReceived| Laravel
    Laravel -->|queue jobs| Redis
    Redis --> Horizon
    Horizon -->|hot-state writes| NatsKV
    Horizon -->|analytics / alerts / automation| Laravel
    Laravel -->|broadcast| Reverb

    classDef external fill:#FFE66D,stroke:#F08C00,color:#000
    classDef runtime fill:#4ECDC4,stroke:#0B7285,color:#fff
    classDef data fill:#A8DADC,stroke:#1864AB,color:#000
    classDef queue fill:#95E1D3,stroke:#087F5B,color:#000

    class Prod,Tunnel external
    class NodeRED,EMQX,Go,Bridge,Laravel,Horizon,Reverb runtime
    class Postgres,NatsKV data
    class Redis queue
```

## Runtime Sequence

This is the normal successful path for production-forwarded telemetry.

```mermaid
sequenceDiagram
    participant Prod as Production forwarder
    participant NodeRED as Node-RED adapter
    participant EMQX as EMQX NATS bridge
    participant Go as Go telemetry-ingester
    participant DB as Postgres / TimescaleDB
    participant Bridge as Laravel Go-event bridge
    participant Events as Laravel events
    participant Horizon as Horizon workers
    participant Reverb as Reverb
    participant NATS as NATS KV hot state

    Prod->>NodeRED: POST /migration/legacy-ingest
    NodeRED->>NodeRED: Decode and normalize vendor payload
    NodeRED->>EMQX: Publish migration.source.* telemetry topic
    EMQX-->>Go: Deliver subscribed NATS subject

    Go->>DB: Refresh registry when TTL expires
    Go->>Go: Expand device_signal_bindings
    Go->>Go: Resolve profile channel
    Go->>DB: Insert ingestion_messages row
    Go->>Go: Validate, mutate, derive values
    Go->>DB: Insert device_telemetry_logs row
    Go->>DB: Mark device online
    Go->>EMQX: Publish iot.v1.ingestion.persisted

 EMQX-->>Bridge: Deliver persisted event
 Bridge->>DB: Verify DeviceTelemetryLog id
 Bridge->>Events: Dispatch scalar TelemetryReceived
 Events->>Horizon: Queue side-effect listeners
 Horizon->>DB: Read telemetry context
    Horizon-->>Reverb: Throttled TelemetryRealtimeUpdated broadcasts
    Horizon-->>NATS: Coalesced hot-state latest-value writes
    Horizon-->>Events: Analytics, alerts, automation complete
```

## Go Ingester Components

The Go service owns only the ingestion hot path. It does not own admin UI, automation rules, alert rules, or dashboard rendering.

```mermaid
flowchart TB
    subgraph Go["Go telemetry-ingester"]
        Subscriber["NATS subscriber<br/>subject filtering"]
        Envelope["Envelope builder<br/>source subject, MQTT topic, payload"]
        Registry["Registry cache<br/>profiles, devices, bindings"]
        Binding["Binding expander<br/>source topic -> concrete device/channel payloads"]
        Resolver["Profile channel resolver<br/>MQTT topic -> device + channel"]
        Dedupe["Deduper<br/>source message id or payload hash"]
        Validator["Validator<br/>required, type, min/max, regex, enum"]
        Mutator["Mutator<br/>JSON-logic expressions"]
        Deriver["Deriver<br/>computed profile parameters"]
        Persistence["Persistence adapter<br/>Postgres writes"]
        Publisher["Internal event publisher<br/>iot.v1.ingestion.*"]
    end

    EMQX["EMQX :4222"] --> Subscriber
    Subscriber --> Envelope
    Envelope --> Binding
    Binding --> Resolver
    Resolver --> Dedupe
    Dedupe --> Validator
    Validator --> Mutator
    Mutator --> Deriver
    Deriver --> Persistence
    Registry --> Binding
    Registry --> Resolver
    Persistence --> Publisher
    Publisher --> EMQX

    Postgres[("Postgres / TimescaleDB")] <--> Registry
    Persistence --> Postgres

    classDef component fill:#4ECDC4,stroke:#0B7285,color:#fff
    classDef broker fill:#FFE66D,stroke:#F08C00,color:#000
    classDef database fill:#A8DADC,stroke:#1864AB,color:#000

    class Subscriber,Envelope,Registry,Binding,Resolver,Dedupe,Validator,Mutator,Deriver,Persistence,Publisher component
    class EMQX broker
    class Postgres database
```

## Database Contract

Go writes the same ingestion and telemetry tables the Laravel pipeline wrote. Laravel still reads these tables for admin screens, dashboards, reports, alerts, and automation.

```mermaid
erDiagram
    DEVICES ||--o{ INGESTION_MESSAGES : "source device"
    DEVICES ||--o{ DEVICE_TELEMETRY_LOGS : "emits"
    DEVICE_PROFILE_VERSIONS ||--o{ DEVICES : "assigned to"
    DEVICE_PROFILE_VERSIONS ||--o{ DEVICE_CHANNELS : "defines"
    DEVICE_PROFILE_VERSIONS ||--o{ PROFILE_DERIVED_PARAMETER_DEFINITIONS : "derives"
    DEVICE_CHANNELS ||--o{ PROFILE_PARAMETER_DEFINITIONS : "validates"
    DEVICE_CHANNELS ||--o{ DEVICE_SIGNAL_BINDINGS : "target channel"
    DEVICES ||--o{ DEVICE_SIGNAL_BINDINGS : "binding source device"
    INGESTION_MESSAGES ||--o{ INGESTION_STAGE_LOGS : "diagnostics"
    INGESTION_MESSAGES ||--o| DEVICE_TELEMETRY_LOGS : "produces"

    DEVICES {
        bigint id
        uuid uuid
        string external_id
        string connection_state
        timestamp last_seen_at
        bigint device_profile_version_id
    }

    INGESTION_MESSAGES {
        uuid id
        string source_subject
        string source_deduplication_key
        string status
        jsonb raw_payload
        jsonb error_summary
        timestamp received_at
        timestamp processed_at
    }

    DEVICE_TELEMETRY_LOGS {
        uuid id
        bigint device_id
        bigint device_channel_id
        uuid ingestion_message_id
        string validation_status
        string processing_state
        jsonb transformed_values
        timestamp recorded_at
    }

    INGESTION_STAGE_LOGS {
        bigint id
        uuid ingestion_message_id
        string stage
        string status
        jsonb errors
        timestamp created_at
    }
```

## Side-Effect Boundary

The bridge keeps downstream Laravel behavior intact while removing Laravel queue work from the ingestion hot path.

```mermaid
flowchart LR
    Go["Go telemetry-ingester"] -->|iot.v1.ingestion.incoming| Bridge["ingestion:consume-go-events"]
    Go -->|iot.v1.ingestion.persisted| Bridge

    Bridge --> Incoming["TelemetryIncoming<br/>raw diagnostics stream"]
    Bridge --> Received["TelemetryReceived<br/>persisted telemetry event"]

 Received --> Broadcast["BroadcastTelemetryRealtimeUpdate<br/>runtime setting + throttle"]
 Broadcast --> Reverb["TelemetryRealtimeUpdated<br/>Reverb dashboard broadcast"]
    Received --> HotState["QueueTelemetryHotStateWrites<br/>coalesced NATS KV latest values"]
    Received --> Analytics["QueueTelemetryAnalyticsPublishes<br/>analytics fan-out"]
    Received --> Alerts["QueueTelemetryThresholdAlertRecords"]
    Received --> Automation["QueueTelemetryAutomationRuns"]

    classDef go fill:#4ECDC4,stroke:#0B7285,color:#fff
    classDef bridge fill:#FFE66D,stroke:#F08C00,color:#000
    classDef event fill:#F3E5F5,stroke:#8E24AA,color:#000
    classDef sidefx fill:#95E1D3,stroke:#087F5B,color:#000

    class Go go
    class Bridge bridge
    class Incoming,Received event
    class Broadcast,HotState,Analytics,Alerts,Automation sidefx
```

## Failure And Rollback Paths

```mermaid
flowchart TD
    Payload["Inbound telemetry payload"] --> Resolve{"Can Go resolve topic<br/>or binding?"}
    Resolve -->|"yes"| Validate{"Payload valid?"}
    Resolve -->|"no"| FailedTerminal["ingestion_messages.status<br/>failed_terminal<br/>reason: channel_not_registered"]

    Validate -->|"valid"| Persist["Persist processed telemetry<br/>device_telemetry_logs.processing_state=processed"]
    Validate -->|"critical invalid"| Invalid["Persist invalid telemetry<br/>processing_state=invalid<br/>status=failed_validation"]
    Validate -->|"warning only"| Warning["Persist processed telemetry<br/>validation_status=warning"]

    Persist --> Events["Publish internal event<br/>Laravel side effects"]
    Invalid --> Events
    Warning --> Events

    Events --> Rollback{"Need rollback?"}
    Rollback -->|"no"| Done["Go path remains active"]
    Rollback -->|"yes"| PHP["Switch compose service back to<br/>php artisan iot:ingest-telemetry<br/>and set driver=laravel"]

    classDef decision fill:#FFE66D,stroke:#F08C00,color:#000
    classDef success fill:#95E1D3,stroke:#087F5B,color:#000
    classDef failure fill:#F38181,stroke:#C92A2A,color:#fff
    classDef rollback fill:#A8DADC,stroke:#1864AB,color:#000

    class Resolve,Validate,Rollback decision
    class Persist,Warning,Events,Done success
    class FailedTerminal,Invalid failure
    class PHP rollback
```

## Operational Notes

- Local compose builds `services/telemetry-ingester/Dockerfile`.
- Production compose expects `TELEMETRY_INGESTER_IMAGE`.
- The Go ingester subscribes to EMQX on port `4222`, not the standalone NATS container, because Node-RED publishes telemetry into EMQX/MQTT.
- `failed_terminal` with `channel_not_registered` usually means the incoming source topic has no active `device_signal_bindings` row.
- Rollback is the PHP path: set the driver to `laravel` and run the legacy `php artisan iot:ingest-telemetry` service instead of the Go container.
