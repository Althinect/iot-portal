# IoT Platform Enterprise-Scale Re-Architecture Strategy

## Executive Summary

Migrate from a single Laravel + NATS + TimescaleDB monolith to a horizontally-scalable, microservices-aligned architecture using NATS JetStream, ClickHouse, Kafka/Redpanda, distributed queues, and a purpose-built data warehouse. Enable independent scaling of ingestion, real-time processing, analytics, and management planes.

---

## 1. Current Architecture Baseline

### Existing Stack

| Layer | Technology | Role |
|-------|-----------|------|
| HTTP API + Admin | Laravel 13 + Filament 5 + Octane/FrankenPHP | UI, REST, management |
| Queue | Redis + Laravel Horizon | Job processing |
| Messaging | NATS 2.x (JetStream) + MQTT bridge | Device telemetry transport |
| Time-Series DB | TimescaleDB 2.18 (PostgreSQL 16) | Operational telemetry storage |
| Hot State | NATS KV (`NatsKvHotStateStore`) | Latest device values |
| Realtime | Laravel Reverb | Browser push |
| Feature Flags | Laravel Pennant | Runtime gating |

### Current Ingestion Pipeline

```
[MQTT Devices]
    └──► [NATS Subject: >]
            └──► IngestTelemetryCommand (NATS subscriber)
                    └──► ProcessInboundTelemetryJob (Redis Queue via Horizon)
                            └──► TelemetryIngestionService
                                    ├── Dedup (IngestionMessage)
                                    ├── Topic resolution (DeviceTelemetryTopicResolver)
                                    ├── Validate → Mutate → Derive
                                    ├── Persist (DeviceTelemetryLog → TimescaleDB)
                                    └── Fan-out (NATS KV / Analytics / Alerts / Automation)
```

### Current Reporting Path (Bottleneck)

`ReportGenerationService` fetches `device_telemetry_logs` directly from TimescaleDB via Eloquent and computes aggregations in PHP application memory. This couples heavy analytical OLAP queries to the OLTP/operational database.

---

## 2. Proposed Distributed Architecture

### 2.1 Architecture Diagram

```
                                 ┌───────────────────────────┐
                                 │         API / Admin        │
                                 │   Laravel (Octane x N)     │
                                 │   Filament Panel           │
                                 │   (device mgmt, config,    │
                                 │    workflows, reports API) │
                                 └──────────┬────────────────┘
                                            │ gRPC / HTTP
                                            ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                         Service Mesh / Event Bus                         │
│                     NATS JetStream Cluster (3 nodes)                     │
│  Subjects: iot.telemetry.raw / iot.telemetry.valid / iot.commands.>     │
│  KV:      device.states / device.presence                               │
│  Object:  schema.registry / retention.policies                          │
│  JetStream: durable streams per tenant per data-center                  │
└───┬──────────────┬──────────────┬──────────────┬───────────────────────┘
    │              │              │              │
    ▼              ▼              ▼              ▼
┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────┐
│ Ingestion│  │ Realtime │  │ Command  │  │ Analytics /      │
│ Service  │  │ Service  │  │ Service  │  │ Export Service   │
│ (Go)     │  │ (Go)     │  │ (Go)     │  │ (Go)             │
│ iotedge  │  │ ws-hub   │  │ cmd-gate │  │ telemetry-sync   │
└────┬─────┘  └────┬─────┘  └────┬─────┘  └───────┬──────────┘
     │             │             │                 │
     ▼             ▼             ▼                 ▼
┌─────────────────────────┐    ┌──────────────────────────────┐
│  Operational DB         │    │  Data Warehouse              │
│  (TimescaleDB / Postgres│    │  (ClickHouse Cluster)        │
│   — hot data only)      │    │  ┌────────────────────────┐  │
│  ┌───────────────────┐  │    │  │ telemetry_wide         │  │
│  │ device_telemetry  │  │    │  │   (MergeTree, sharded) │  │
│  │   logs (30 days)  │  │    │  │                        │  │
│  │                   │  │    │  │ system_metrics         │  │
│  │ ingestion_messages│  │    │  │ device_state_history   │  │
│  │ devices           │  │    │  │ alert_events           │  │
│  │ schema_registry   │  │    │  │ automation_runs        │  │
│  └───────────────────┘  │    │  │ report_mv              │  │
│                         │    │  └────────────────────────┘  │
│  Queue:                 │    │                              │
│  Redis Cluster          │    │  Kafka / Redpanda           │
│  (job dispatching)      │    │  (warehouse ingest bus)     │
└─────────────────────────┘    └──────────────────────────────┘
```

### 2.2 Layer-by-Layer Specification

#### A. Edge Ingestion Layer — `iot-ingest` service (Go / Rust / PHP-FPM isolated)

- Subscribes to NATS `iot.telemetry.raw.>` (JetStream consumer, at-least-once)
- Schema validation (JSON Schema or protobuf validate)
- Dedup (NATS KV dedup key lookup or Redis Cluster SET NX EX)
- Writes enriched envelope to:
  - **NATS JetStream** `iot.telemetry.valid.<tenant_id>` (queue stream → consumed by processing workers)
  - **Operational TimescaleDB** (last 30 days of hot telemetry)
- Does NOT call PHP application code → eliminates queue + job serialization overhead

PHP `IngestTelemetryCommand` remains as a NATS subscribe listener but its responsibility shrinks to dispatching `ProcessInboundTelemetryJob`. The new Go service owns raw ingress.

#### B. Stream Processing Layer — NATS JetStream Workers (Go / Rust)

- Multiple consumer groups on `iot.telemetry.valid.<tenant_id>`:
  - **persistence-consumer**: writes to TimescaleDB operational store
  - **state-consumer**: updates NATS KV (`device.states.<device_id>`)
  - **alert-consumer**: evaluates threshold policies → emits alert events
  - **automation-consumer**: triggers workflow runs
  - **sync-consumer**: publishes to Kafka/Redpanda `telemetry_export` topic

JetStream's flow control and ack semantics replace manual queue management. No Laravel Horizon needed for the ingestion path.

#### C. Operational Database — TimescaleDB (non-distributed, single node, sized for hot window)

- Retains only **30 days** of raw/transformed telemetry (`device_telemetry_logs`)
- `continuous aggregates` for recent rollups (hourly, daily per device)
- `data_retention_policy` + `add_compression_policy` native to TimescaleDB replaces custom `ApplyStorageLifecycle` command and `config/ingestion.php` retention env vars
- `ingestion_messages` and `ingestion_stage_logs` migrate to TimescaleDB
- **Do not scale this cluster horizontally** — it is a hot/warm store with bounded retention

#### D. Management Plane — Laravel 13 + Filament (unchanged location, scaled horizontally)

- Remains as the operator/admin UI and API for:
  - Device CRUD, schema management, certificate issuance
  - Threshold policy CRUD, automation workflow builder
  - Dashboard layout, user/org management, RBAC
- Reads hot device state from NATS KV (low-latency, no DB round-trip)
- Reads device metadata (schemas, policies, orgs) from **Operational PostgreSQL/TimescaleDB**
- Report generation queries the **Data Warehouse (ClickHouse)** via a new `ReportingWarehouseClient`
- Served by multiple Octane/FrankenPHP instances behind a load balancer; stateless → horizontal scale

#### E. Data Warehouse — ClickHouse Cluster

**Topology:**
- 3-node cluster (1 shard, 3 replicas) or expandable to multi-shard
- ReplicatedMergeTree engine with ZooKeeper or ClickHouse Keeper
- Shared nothing storage; each node holds full data → linear read scaling

**Key Tables:**

| Table | Engine | Purpose |
|-------|--------|---------|
| `telemetry_wide` | `ReplicatedMergeTree` | Flattened per-reading row with all params as columns for fast OLAP filtering |
| `device_state_history` | `CollapsingMergeTree` | State transitions with `sign` column for efficient duration aggregation |
| `alert_events` | `ReplicatedMergeTree` | Alert/incident timeline |
| `automation_runs` | `ReplicatedMergeTree` | Workflow execution log |
| `report_mv` | `SummingMergeTree` | Pre-aggregated materialized views per report grouping |

**Ingestion:**
- Go `sync-consumer` writes Kafka/Redpanda offsets → ClickHouse Kafka Sorted Merge Tree or native Kafka engine
- Batched inserts (1–5s batches, 50K–500K rows per insert)
- ClickHouse `buffer` table for write smoothing during spikes

#### F. Message Bus — Kafka / Redpanda (added)

- Topic: `telemetry_export` 
- 12 partitions, replication factor 3
- Retention: 7 days (catastrophic replay window)
- Replaces ad-hoc NATS fan-out for warehouse ingest
- Provides exactly-once semantics for the warehouse sync path

#### G. Hot State & Control Plane — NATS JetStream KV + Object Store

- `device.states.<device_id>` — last known values (TTL: 24h, refreshed on each reading)
- `device.presence.<device_id>` — last-seen timestamp
- `commands.<device_id>` — command stack (JetStream stream, per-device consumer)
- `schema.registry` — device schema definitions (Object Store)
- Replaces scattered Redis and DB calls for device state reads

#### H. Queue / Job Layer — Redis Cluster

- Retained for Laravel application jobs that are NOT on the critical ingress path:
  - Report generation (warehouse queries)
  - CSV export
  - Notification dispatch
  - Device provisioning
- Redis Cluster (3 primaries + 3 replicas) replaces single Redis instance
- Laravel Horizon reconfigured to use `phpredis` cluster connections
- `INGESTION_PIPELINE_QUEUE_CONNECTION` env var redirects ingestion jobs to the new Go path; other queues remain Redis

#### I. Realtime / Dashboard — Reverb + WebSocket Hub (Go)

- Replace Laravel Reverb single-process with a dedicated `ws-hub` Go service
- Reads device state updates from NATS JetStream subscriber (no DB polling)
- Pushes to browser via WebSocket
- Dashboard snapshots written directly to ClickHouse → `iot_dashboard_snapshots` table

---

## 3. Technology Selection Rationale

| Technology | Current | Proposed | Rationale |
|-----------|---------|----------|-----------|
| Ingestion transport | Direct NATS → PHP queue | Go service → NATS JetStream | Eliminates PHP serialization cost on hot path; Go handles 100K+ msgs/sec with low GC |
| Hot state | NATS KV + DB reads | NATS KV only | KV is in-memory on each JetStream node; sub-ms reads; eliminates DB round-trips |
| Operational TSDB | TimescaleDB (single) | TimescaleDB (single, bounded) | Correctly scoped to 30-day hot window; native compression handles cost |
| Analytical DB | TimescaleDB (same) | ClickHouse cluster | OLAP-optimized; 10–100× faster on wide-aggregation queries; independent scaling |
| Warehouse ingest | ETL cron / manual | Kafka + ClickHouse Kafka engine | Decoupled, backpressure-aware, no data loss on warehouse downtime |
| Message bus | NATS + Redis | NATS JetStream + Redpanda | JetStream owns durable streams; Redpanda/Kafka owns warehouse replay/batch pipelines |
| Queue | Redis single | Redis Cluster | Horizontal queue scaling; Horizon workers scale independently |
| Realtime | Laravel Reverb | Go ws-hub | Single-process Reverb is a bottleneck at 10K+ concurrent connections |
| Management UI | Laravel Filament | Laravel Filament (xN) | No change needed; becomes stateless, horizontally scalable |
| Schema/workflows | All in TimescaleDB | Same (operational side) | Management metadata is low-volume; no need to relocate |

---

## 4. Data Warehouse Ingest Architecture

Two implementation options for the existing monolith context are presented for comparison.

---

### Approach A — Monolith-Integrated Warehouse

**Architecture:**

Telemetry rows are written to `device_telemetry_logs` (TimescaleDB) by the existing Laravel pipeline. A scheduled Artisan command or Horizon job runs ETL transformations that copy rows into ClickHouse tables over the same database network or a direct connection.

```
[Laravel Horizon Job - every 60s]
    └──► DeviceTelemetryLog::where('recorded_at', '<', now()->subMinutes(5))->chunk()
            └──► Transform to ClickHouse row format
                    └──► ClickHouse HTTP insert (batched)
```

ClickHouse is queried by `ReportGenerationService` via a new `WarehouseClient` injected into the service container.

**Scalability:** Low. The Laravel process that does ETL competes with queue workers and HTTP requests for CPU and memory. Horizon throughput drops during large nightly batch imports. ClickHouse cannot ingest beyond what one Laravel worker can produce because there is one writer.

**Ingestion Performance:** Medium. Eloquent `chunk()` reads from TimescaleDB, applies PHP-level transformations, and inserts via ClickHouse HTTP interface. Acceptable at < 100K readings/day. Degrades sharply at enterprise volume (> 1M/day) due to:
- Row-by-row PHP transformation overhead
- No backpressure — if ClickHouse is slow, Horizon queue grows, blocking other jobs
- TimescaleDB and ClickHouse compete on the same PostgreSQL network connection pool

**Architectural Complexity:** Low initially, high long-term cost. Only one codebase to maintain. But operational coupling means every monitoring, retry, and scaling decision affects both OLTP and OLAP paths. Schema changes to `device_telemetry_logs` must be duplicated in the ETL mapper. The monolith becomes the SPOF for all data flow.

---

### Approach B — Distributed Warehouse (Proposed)

**Architecture:**

The Go `sync-consumer` subscribes to NATS JetStream `iot.telemetry.valid.<tenant_id>` and writes directly to Kafka/Redpanda `telemetry_export`. ClickHouse reads from Kafka via its native Kafka engine. The Laravel app is not on the critical warehouse path.

```
[Go sync-consumer]
    └──► Kafka/Redpanda telemetry_export (12 partitions)
            └──► ClickHouse Kafka engine
                    └──► telemetry_wide table
```

Laravel reads warehouse data exclusively. Write path to warehouse is decoupled from the PHP lifecycle.

**Scalability:** High. The Go sync-consumer can be horizontally scaled (multiple instances, same JetStream consumer group) without touching the Laravel fleet. Kafka partitions allow 12× parallel ingest. ClickHouse scales read capacity by adding replicas. Each tier scales independently.

**Ingestion Performance:** High. Go consumes NATS JetStream with zero-copy where possible. Kafka batching (default 100ms or 64KB) absorbs micro-bursts without per-row overhead. ClickHouse inserts are batched at the engine level (hundreds of thousands of rows per second per node). PHP transformation code is eliminated from the hot path.

**Architectural Complexity:** Higher upfront. Requires deploying and monitoring a Go service, a Kafka/Redpanda cluster, and a ClickHouse cluster. But each component has a narrow responsibility and failure domain. Retry, replay, and backpressure are handled by mature infrastructure (JetStream + Kafka + ClickHouse) rather than Laravel Horizon logic.

---

### Comparison Matrix

| Dimension | Approach A — Monolith-Integrated | Approach B — Distributed |
|-----------|----------------------------------|--------------------------|
| **Scalability** | Limited by single Laravel worker pool; ETL competes with queues | Each tier scales independently; Go consumers + ClickHouse replicas absorb enterprise volume |
| **Ingestion Performance** | ~50K readings/day per worker; degrades at enterprise scale | ~1M+ readings/day per sync-consumer instance; sub-second warehouse latency |
| **Architectural Complexity** | Low initial code surface; high operational coupling | Higher deployment surface; clear failure domains, operational independence |
| **Failure Isolation** | ClickHouse DB outage blocks Horizon queue (shared process pool) | Kafka buffers writes; ClickHouse outage does not affect operational ingress |
| **Replay / Reprocessing** | Must re-run ETL job; risk of double-insert | Kafka replay window (7 days) + JetStream re-deliver; idempotent inserts |
| **Team Autonomy** | One team owns all data paths | Data platform team owns warehouse; app team owns management plane |
| **Operational Cost** | Low infrastructure count; high coordination cost at scale | Moderate infrastructure count; low coordination cost at scale |
| **Suitability for SMB** | Acceptable for < 1K devices, < 100K readings/day | Overkill for SMB |
| **Suitability for Enterprise** | Unsuitable beyond pilot tier | Required for > 10K devices, > 1M readings/day, multi-tenant SLAs |

---

## 5. Migration Roadmap

### Phase 1 — Strangle the Monolith (Weeks 1–4)

- Deploy NATS JetStream cluster (3 nodes); migrate existing NATS core to JetStream
- Deploy Go `iot-ingest` service; route a percentage of device traffic to it (shadow mode, no writes)
- Deploy ClickHouse 3-node cluster; bootstrap warehouse schema
- Deploy Redpanda 3-node cluster
- Add `WarehouseClient` interface and implementation to Laravel; wire `ReportGenerationService` to use it (with fallback to TimescaleDB)
- Feature flag `warehouse.read_enabled` — switch reporting reads to ClickHouse for one non-critical tenant

### Phase 2 — Decouple Ingestion (Weeks 5–8)

- Route 100% of device telemetry to Go `iot-ingest`
- Go service writes hot data to TimescaleDB (operational) AND Kafka `telemetry_export` simultaneously
- ClickHouse ingests from Kafka; compare row counts as a data-integrity check
- Retire `ProcessInboundTelemetryJob` for active devices; keep it as fallback
- Migrate `NatsKvHotStateStore` reads to Go-managed NATS KV (no code change if existing keys remain compatible)

### Phase 3 — Retire Monolith Coupling (Weeks 9–12)

- Remove `device_telemetry_logs` writes from Laravel (Go owns all writes)
- Implement `continuous aggregates` in TimescaleDB for 7-day and 30-day rollups
- Move reporting CSV generation to read from ClickHouse exclusively
- Remove `ApplyStorageLifecycle` Artisan command; configure TimescaleDB native retention policies
- Remove `TelemetryPersistenceService` from the PHP pipeline (or leave as no-op for legacy job safety)

### Phase 4 — Scale the Platform (Ongoing)

- Add ClickHouse shards when data volume warrants
- Add `ws-hub` Go service for WebSocket dashboard (replaces Reverb for data channels)
- Scale Laravel Octane workers behind load balancer (stateless after Phase 3)
- Implement tenant-level data partitioning in ClickHouse (by `organization_id`)
- Add Prometheus + Grafana observability per tier (ingestion lag, warehouse lag, queue depth, page latency SLI)

---

## 6. Risk Register

| Risk | Mitigation |
|------|-----------|
| ClickHouse schema drift vs. TimescaleDB | Source-of-truth schema in OpenAPI/Protobuf; both sides generated from same definition |
| JetStream vs. Redis queue semantics gap | Implement idempotency keys in Go consumer; use JetStream `deliver_policy: by_start_time_sequence` for replay |
| Laravel app hard-couples to Eloquent `DeviceTelemetryLog` in reporting | Abstract via `TelemetryQueryInterface`; implement `TimescaleTelemetryQuery` and `ClickHouseTelemetryQuery`; swap via config |
| Operator learning curve for ClickHouse | Run ClickHouse in read-only mode for 2 sprints before cutting over writes; train team on `system.metrics` and `merge` operations |
| NATS JetStream cluster operational overhead | Start with embedded NATSJetStream (single node with file store); scale to 3-node after smoke test |

---

## 7. Summary Recommendation

- **Adopt Approach B (Distributed Warehouse)** for any trajectory that targets enterprise (> 10K devices, SLA commitments, multi-region).
- **Approach A (Monolith-Integrated)** is acceptable as a short-term bridge for pure SMB deployments but becomes a scaling liability within 12–18 months.
- The Go + JetStream + ClickHouse + Kafka stack is the minimum viable distributed architecture that preserves existing Laravel + Filament investment for management plane while lifting the data plane clear of PHP application constraints.
