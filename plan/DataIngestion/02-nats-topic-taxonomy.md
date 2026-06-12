# NATS Topic Taxonomy

## Objective
Keep subjects deterministic and low-cardinality, avoid feedback loops, and preserve clean consumer routing.

## Subject Classes
- Inbound telemetry: device-originated subjects (example: `energy.main-energy-meter-01.telemetry`).
- Analytics outbound: `iot.v1.analytics.<env>.<org>.<device>.<topic>`.
- Invalid outbound: `iot.v1.invalid.<env>.<org>.<reason>`.

## Taxonomy Map
```mermaid
flowchart TD
    A["NATS Subjects"]
    A --> B["Inbound Telemetry"]
    A --> C["Analytics Outbound"]
    A --> D["Invalid Outbound"]
    A --> E["System / Internal"]

    B --> B1["energy.main-energy-meter-01.telemetry"]
    B --> B2["devices.rgb-led-01.state"]

    C --> C1["iot.v1.analytics.local.1.device.telemetry"]
    D --> D1["iot.v1.invalid.local.1.validation"]

    E --> E1["$JS.*"]
    E --> E2["$KV.*"]
    E --> E3["_REQS.*"]
    E --> E4["_INBOX.*"]
```

## Listener Filtering (Current)
Even if listener subject is broad (`>`), ingestion command only queues messages when:
1. Subject is not internal/system.
2. Subject is not analytics/invalid loopback.
3. Subject resolves to a known device+schema telemetry topic.

```mermaid
flowchart LR
    A["Incoming NATS subject"] --> B{"Internal / system?"}
    B -->|Yes| X["Drop"]
    B -->|No| C{"Analytics / invalid prefix?"}
    C -->|Yes| X
    C -->|No| D{"Resolvable topic?"}
    D -->|No| X
    D -->|Yes| E["Dispatch ingestion job"]
```

## Anti-Bloat Rules
- Fixed-depth subject format per class.
- Only sanitized tokens (lowercase, `a-z0-9_-`).
- No free-text segments from UI/user input.
- New subject classes require explicit code path.

## Recommended Stream Grouping
- Raw telemetry stream: inbound device subjects.
- Analytics stream: `iot.v1.analytics.>`.
- Invalid stream: `iot.v1.invalid.>`.
- KV bucket (`device-states`) for latest-state reads only.

## Loop Prevention
- Do not subscribe ingestion pipeline to analytics/invalid streams unless explicitly segregated.
- Keep outbound publish prefixes distinct from inbound telemetry namespace.
