# Data Ingestion Module - Architecture

## Architectural Model

The ingestion pipeline is a staged orchestrator with explicit stage logging.

```mermaid
flowchart TD
  A[Inbound telemetry envelope<br/>IncomingTelemetryEnvelope] --> B[Queue entry<br/>ProcessInboundTelemetryJob]
  B --> C[Pipeline orchestration<br/>TelemetryIngestionService]
  C --> D[Deduplicate message<br/>IngestionMessage]
  D --> E[Resolve device + publish topic<br/>DeviceTelemetryTopicResolver]
  E --> F[Validate payload<br/>TelemetryValidationService]
  F -->|invalid| G[Persist invalid telemetry<br/>TelemetryPersistenceService → DeviceTelemetryLog]
  F -->|valid + inactive device| H[Persist inactive_skipped telemetry<br/>TelemetryPersistenceService → DeviceTelemetryLog]
  F -->|valid + active device| I[Mutate values<br/>TelemetryMutationService]
  I --> J[Derive computed values<br/>TelemetryDerivationService]
  J --> K[Persist processed telemetry<br/>TelemetryPersistenceService → DeviceTelemetryLog]
  G --> L[Emit downstream side effects<br/>TelemetryReceived event]
  H --> L
  K --> L
  L --> M[Hot-state write<br/>QueueTelemetryHotStateWrites → NatsKvHotStateStore]
  L --> N[Analytics publish<br/>QueueTelemetryAnalyticsPublishes → TelemetryAnalyticsPublishService]
  L --> O[Threshold evaluation<br/>QueueTelemetryThresholdAlertRecords]
  L --> P[Automation fan-out<br/>QueueTelemetryAutomationRuns]
  M --> Q[Completed or failed_terminal]
  N --> Q
  O --> Q
  P --> Q

  classDef transport fill:#E3F2FD,stroke:#1E88E5,color:#0D47A1,stroke-width:2px;
  classDef orchestration fill:#E8F5E9,stroke:#43A047,color:#1B5E20,stroke-width:2px;
  classDef validation fill:#FFF8E1,stroke:#F9A825,color:#7F6000,stroke-width:2px;
  classDef persistence fill:#F3E5F5,stroke:#8E24AA,color:#4A148C,stroke-width:2px;
  classDef eventing fill:#FCE4EC,stroke:#D81B60,color:#880E4F,stroke-width:2px;
  classDef outcome fill:#ECEFF1,stroke:#546E7A,color:#263238,stroke-width:2px;

  class A,B transport;
  class C,D,E orchestration;
  class F,I,J validation;
  class G,H,K persistence;
  class L,M,N,O,P eventing;
  class Q outcome;
```

## Runtime Collaboration View

This view is useful during a walkthrough because it shows the exact handoff between command, job, orchestrator, storage, and downstream listeners.

```mermaid
sequenceDiagram
  participant Cmd as IngestTelemetryCommand
  participant Job as ProcessInboundTelemetryJob
  participant Pipe as TelemetryIngestionService
  participant Msg as IngestionMessage
  participant Topic as DeviceTelemetryTopicResolver
  participant Validate as TelemetryValidationService
  participant Persist as TelemetryPersistenceService
  participant Log as DeviceTelemetryLog
  participant Event as TelemetryReceived
  participant SideFx as Alert / Automation / Analytics listeners

  Cmd->>Job: dispatch envelope DTO
  Job->>Pipe: ingest(envelope)
  Pipe->>Msg: create or mark duplicate
  Pipe->>Topic: resolve mqtt topic to device + topic
  Pipe->>Validate: validate extracted values
  alt valid active telemetry
    Pipe->>Persist: persist processed telemetry
    Persist->>Log: insert transformed telemetry row
    Pipe->>Event: dispatch TelemetryReceived
    Event->>SideFx: queue fan-out listeners
  else invalid or inactive telemetry
    Pipe->>Persist: persist invalid/inactive telemetry
    Persist->>Log: insert telemetry row with processing_state
    Pipe->>Event: dispatch TelemetryReceived
    Event->>SideFx: queue fan-out listeners
  end
```

## Component Responsibilities

| Component                          | Responsibility                                                                |
| ---------------------------------- | ----------------------------------------------------------------------------- |
| `IngestTelemetryCommand`           | NATS subscriber; filters subjects; dispatches queue job                       |
| `ProcessInboundTelemetryJob`       | Queue entry point that reconstructs envelope DTO                              |
| `TelemetryIngestionService`        | Full orchestration and stage transitions                                      |
| `DeviceTelemetryTopicResolver`     | Topic registry with TTL refresh                                               |
| `TelemetryValidationService`       | Parameter extraction + validation error classification                        |
| `TelemetryMutationService`         | Field mutation pass                                                           |
| `TelemetryDerivationService`       | Derived metric evaluation with dependency order                               |
| `TelemetryPersistenceService`      | Writes `DeviceTelemetryLog`, marks presence online, emits `TelemetryReceived` |
| `NatsKvHotStateStore`              | Writes processed values into NATS KV hot-state bucket                         |
| `TelemetryAnalyticsPublishService` | Conditional analytics publish based on feature/config                         |

## Database Structure and Models

The ingestion module centers around one durable pipeline record and one final telemetry record:

- `App\Domain\DataIngestion\Models\IngestionMessage` → `ingestion_messages`
- `App\Domain\DataIngestion\Models\IngestionStageLog` → `ingestion_stage_logs`
- `App\Domain\Telemetry\Models\DeviceTelemetryLog` → `device_telemetry_logs`

Supporting lookups come from:

- `App\Domain\DeviceManagement\Models\Device`
- `App\Domain\DeviceSchema\Models\SchemaVersionTopic`
- `App\Domain\DeviceSchema\Models\ParameterDefinition`
- `App\Domain\DeviceSchema\Models\DerivedParameterDefinition`

```mermaid
flowchart LR
  subgraph Models[Eloquent models]
    IM[IngestionMessage]
    ISL[IngestionStageLog]
    DTL[DeviceTelemetryLog]
    DEV[Device]
    SVT[SchemaVersionTopic]
    PD[ParameterDefinition]
    DPD[DerivedParameterDefinition]
  end

  subgraph Tables[Database tables]
    T1[(ingestion_messages)]
    T2[(ingestion_stage_logs)]
    T3[(device_telemetry_logs)]
    T4[(devices)]
    T5[(schema_version_topics)]
    T6[(parameter_definitions)]
    T7[(derived_parameter_definitions)]
  end

  IM --> T1
  ISL --> T2
  DTL --> T3
  DEV --> T4
  SVT --> T5
  PD --> T6
  DPD --> T7

  T1 -->|has many| T2
  T1 -->|produces one telemetry log| T3
  T4 -->|source device| T1
  T5 -->|resolved topic| T1
  T5 -->|owns parameter contract| T6
  DTL -->|stores transformed values for| T4
  DTL -->|stores topic readings for| T5

  classDef model fill:#E8F5E9,stroke:#2E7D32,color:#1B5E20,stroke-width:2px;
  classDef table fill:#E3F2FD,stroke:#1565C0,color:#0D47A1,stroke-width:2px;

  class IM,ISL,DTL,DEV,SVT,PD,DPD model;
  class T1,T2,T3,T4,T5,T6,T7 table;
```

### Key persisted structures

| Model / Table                                  | Why it matters in the walkthrough                                   | Key fields to mention                                                                                                       |
| ---------------------------------------------- | ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `IngestionMessage` / `ingestion_messages`      | Durable audit record for each inbound payload                       | `source_subject`, `source_message_id`, `source_deduplication_key`, `status`, `error_summary`, `received_at`, `processed_at` |
| `IngestionStageLog` / `ingestion_stage_logs`   | Per-stage diagnostics for replay and troubleshooting                | `stage`, `status`, `duration_ms`, `input_snapshot`, `output_snapshot`, `change_set`, `errors`                               |
| `DeviceTelemetryLog` / `device_telemetry_logs` | Final time-series record used by dashboards and downstream features | `raw_payload`, `transformed_values`, `validation_errors`, `processing_state`, `recorded_at`                                 |
| `SchemaVersionTopic`                           | Defines the publish topic contract resolved from MQTT subject       | `device_schema_version_id`, `suffix`, `direction`                                                                           |
| `ParameterDefinition`                          | Defines extraction and validation rules for a topic payload         | `json_path`, `type`, `required`, `is_critical`, `mutation_expression`                                                       |
| `DerivedParameterDefinition`                   | Defines calculated metrics derived from validated values            | `key`, `expression`, `dependencies`                                                                                         |

## Domain and Service Boundaries

| Layer             | Main classes                                                                                                                                                   | Responsibility                                                         |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| Transport / entry | `IncomingTelemetryEnvelope`, `IngestTelemetryCommand`, `ProcessInboundTelemetryJob`                                                                            | Receives NATS/MQTT traffic and turns it into a typed ingestion request |
| Orchestration     | `TelemetryIngestionService`                                                                                                                                    | Owns the pipeline order, status transitions, and stage logging         |
| Schema resolution | `DeviceTelemetryTopicResolver`, `TelemetrySchemaMetadataCache`                                                                                                 | Resolves which device/topic/schema contract applies to a payload       |
| Value processing  | `TelemetryValidationService`, `TelemetryMutationService`, `TelemetryDerivationService`                                                                         | Extracts, validates, transforms, and derives telemetry fields          |
| Persistence       | `TelemetryPersistenceService`, `IngestionMessage`, `IngestionStageLog`, `DeviceTelemetryLog`                                                                   | Writes pipeline and telemetry records to the database                  |
| Side effects      | `TelemetryReceived`, `QueueTelemetryHotStateWrites`, `QueueTelemetryAnalyticsPublishes`, `QueueTelemetryThresholdAlertRecords`, `QueueTelemetryAutomationRuns` | Fans telemetry out to realtime, analytics, alerts, and automation      |

## Stage-to-Class Map

| Pipeline stage   | Main classes to point at                                                               |
| ---------------- | -------------------------------------------------------------------------------------- |
| Ingress          | `IncomingTelemetryEnvelope`, `ProcessInboundTelemetryJob`, `TelemetryIngestionService` |
| Deduplication    | `TelemetryIngestionService`, `IngestionMessage`                                        |
| Topic resolution | `DeviceTelemetryTopicResolver`, `SchemaVersionTopic`, `Device`                         |
| Validation       | `TelemetryValidationService`, `ParameterDefinition`                                    |
| Mutation         | `TelemetryMutationService`, `ParameterDefinition`                                      |
| Derivation       | `TelemetryDerivationService`, `DerivedParameterDefinition`                             |
| Persistence      | `TelemetryPersistenceService`, `DeviceTelemetryLog`, `IngestionStageLog`               |
| Fan-out          | `TelemetryReceived`, alert/automation/analytics listeners                              |

## Data Model Notes

```mermaid
erDiagram
    INGESTION_MESSAGES ||--o{ INGESTION_STAGE_LOGS : has
    INGESTION_MESSAGES ||--o| DEVICE_TELEMETRY_LOGS : produces
    ORGANIZATIONS ||--o{ INGESTION_MESSAGES : owns
    DEVICES ||--o{ INGESTION_MESSAGES : source
    SCHEMA_VERSION_TOPICS ||--o{ INGESTION_MESSAGES : topic

    INGESTION_MESSAGES {
      uuid id
      string source_subject
      string source_protocol
      string source_message_id
      string source_deduplication_key
      string status
      jsonb raw_payload
      jsonb error_summary
      timestamp received_at
      timestamp processed_at
    }

    INGESTION_STAGE_LOGS {
      bigint id
      uuid ingestion_message_id
      string stage
      string status
      int duration_ms
      jsonb input_snapshot
      jsonb output_snapshot
      jsonb change_set
      jsonb errors
    }
```

When presenting this diagram live, it helps to summarize it as:

- `ingestion_messages` answers: “What happened to this payload?”
- `ingestion_stage_logs` answers: “Where in the pipeline did it fail or slow down?”
- `device_telemetry_logs` answers: “What telemetry did the business features finally consume?”

## Status and Stage Semantics

### `IngestionStatus`

- `queued`
- `processing`
- `completed`
- `failed_validation`
- `inactive_skipped`
- `failed_terminal`
- `duplicate`

### `IngestionStage`

- `ingress`
- `validate`
- `mutate`
- `derive`
- `persist`
- `publish`

## Deduplication Strategy

`IncomingTelemetryEnvelope::deduplicationKey()`:

- prefers source message id when present,
- otherwise hashes subject + payload + received timestamp.

The dedup key is unique in `ingestion_messages`. Existing rows become `duplicate` status.

## Publish Stage Failure Handling

Hot-state and analytics publish are isolated in try/catch blocks.

If either fails:

- telemetry log `processing_state` is set to `publish_failed`,
- publish stage log is written with errors,
- ingestion message becomes `failed_terminal`.

This preserves persisted telemetry while exposing post-persist failure visibility.

## Configuration Surface

`config/ingestion.php` controls:

- feature defaults (`enabled`, `driver`, `publish_analytics`),
- queue connection and queue name,
- resolver registry TTL,
- stage snapshot capture,
- NATS host/port/subject and analytics prefixes.

## Operational Notes

- Listener ignores internal NATS subjects (`$JS.`, `$KV.`, `_INBOX.`, `_REQS.`) and analytics/invalid subjects to avoid loops.
- Redis queue with `phpredis` requires extension availability; command warns when missing.
- Stage logs are suitable for post-incident replay analysis and performance profiling.
