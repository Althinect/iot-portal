# Platform Logging and Diagnostics

## Purpose

This document defines how logging is used across the IoT Portal after the logging rationalization pass.

The goal is simple:

- keep production logs operational and low-noise,
- keep database records as the primary audit trail,
- enable deep diagnostics only for the domain being investigated,
- avoid hot-path success logging that inflates log volume and Pulse noise.

## Default Policy

### Severity Rules

| Level | Use for |
|------|---------|
| `error` | Terminal failure that needs operator attention |
| `warning` | Degraded behavior, bad external input, retries, unknown device/topic/payload, non-fatal infrastructure issues |
| `info` | Low-frequency process lifecycle only |
| `debug` | Success-path diagnostics and payload-level tracing during investigations |

### Audit vs Operational Logging

Normal success-path auditability should come from persisted records, not file logs.

Primary audit records:

- `device_command_logs`
- `automation_runs`
- `automation_run_steps`
- `ingestion_messages`
- `device_telemetry_logs`
- `report_runs`

File logs are operational. They should answer:

- what is failing,
- what is degraded,
- what needs intervention,
- what should be traced temporarily during an incident.

They should not mirror every successful state transition.

## Channels and Defaults

### Main Channels

| Channel | Purpose | File |
|--------|---------|------|
| `stack` | General application logging | `storage/logs/laravel-YYYY-MM-DD.log` |
| `device_control` | Device control, feedback, presence, MQTT/NATS operational logs | `storage/logs/device-control-YYYY-MM-DD.log` |
| `automation_pipeline` | Automation trigger matching and run execution logs | `storage/logs/automation-pipeline-YYYY-MM-DD.log` |

### Environment Defaults

For `local` and `testing`:

- `device_control` defaults to `debug`
- `automation_pipeline` defaults to `debug`

For non-local environments such as `staging` and `production`:

- `device_control` defaults to `warning`
- `automation_pipeline` defaults to `warning`

The default app channel still uses the normal `LOG_LEVEL` setting.

### Operational Overrides

Per-domain overrides remain the main incident tool:

```dotenv
LOG_LEVEL=warning
DEVICE_CONTROL_LOG_LEVEL=warning
AUTOMATION_LOG_LEVEL=warning
```

Raise only the channel under investigation:

```dotenv
DEVICE_CONTROL_LOG_LEVEL=debug
AUTOMATION_LOG_LEVEL=warning
```

Do not use a global debug switch for incident diagnosis.

## Domain-Specific Logging Rules

### Device Control and Presence

Default behavior:

- listener startup remains visible,
- publish failures, retries, unknown payloads, unknown devices, reconciliation exceptions, and health-check failures remain logged,
- per-message success narration is `debug`.

Examples of success-path events that are now `debug`:

- command dispatch start / send success,
- inbound state match,
- matched command feedback,
- device online / offline transition,
- MQTT handshake / PUBACK details,
- message reconciliation detail.

`iot:check-device-health` should not emit a log entry on every clean run. It logs only when:

- devices are actually marked offline, or
- the health check itself fails.

### Automation

Automation uses database state as the audit trail.

Default behavior:

- missing workflow data, invalid node config, dispatch failures, and run exceptions remain logged,
- matched workflows, run start, run finish, node success, and step-recorded events are `debug`,
- failed runs caused by known workflow/runtime issues emit a `warning` summary,
- unexpected run exceptions emit `error`.

### Data Ingestion

Data ingestion diagnostics are primarily persisted in the database rather than written to the app log.

`ingestion_messages` stays always-on as the message-level summary record.

`ingestion_stage_logs` is now controlled by:

```dotenv
INGESTION_STAGE_LOG_MODE=failures
INGESTION_STAGE_LOG_SAMPLE_RATE=0
INGESTION_CAPTURE_STAGE_SNAPSHOTS=true
INGESTION_CAPTURE_SUCCESS_STAGE_SNAPSHOTS=false
```

Supported stage log modes:

| Mode | Behavior |
|------|----------|
| `failures` | Persist stage rows only for failed stages or failed messages |
| `sampled` | Persist successful stage rows for a deterministic sample of messages |
| `all` | Persist successful stage rows for every message |

Snapshot rules:

- failed stages may persist snapshots,
- successful stages do not persist snapshots unless `INGESTION_CAPTURE_SUCCESS_STAGE_SNAPSHOTS=true`,
- `failures` is the normal production setting.

### Reporting

Reporting stays failure-focused.

Expected logging:

- storage write failures,
- report generation exceptions,
- write-to-disk warnings.

Success-path report generation does not need dedicated logs.

## Incident Workflow

### Normal Steady State

Recommended staging / production env:

```dotenv
LOG_LEVEL=warning
DEVICE_CONTROL_LOG_LEVEL=warning
AUTOMATION_LOG_LEVEL=warning
INGESTION_STAGE_LOG_MODE=failures
INGESTION_CAPTURE_SUCCESS_STAGE_SNAPSHOTS=false
```

### Investigating a Device Control Issue

1. Raise only `DEVICE_CONTROL_LOG_LEVEL=debug`.
2. Reproduce the issue briefly.
3. Review `device-control` logs together with:
   - `device_command_logs`
   - `devices`
   - `device_desired_topic_states`
4. Return the level to `warning`.

### Investigating an Automation Issue

1. Raise only `AUTOMATION_LOG_LEVEL=debug`.
2. Reproduce with a known telemetry event.
3. Review logs together with:
   - `automation_telemetry_triggers`
   - `automation_runs`
   - `automation_run_steps`
   - downstream `device_command_logs`
4. Return the level to `warning`.

### Investigating an Ingestion Issue

Start with database inspection:

- `ingestion_messages`
- `ingestion_stage_logs`
- `device_telemetry_logs`

If more success-path detail is required:

1. set `INGESTION_STAGE_LOG_MODE=sampled` for controlled capture, or `all` for short-lived deep tracing,
2. optionally set `INGESTION_CAPTURE_SUCCESS_STAGE_SNAPSHOTS=true`,
3. reproduce briefly,
4. revert back to `failures`.

## Pulse and Telescope Expectations

The reduced logging policy is designed to keep observability tools useful:

- Pulse should highlight real slow queries and slow writes instead of log-amplified noise.
- Telescope already records only `error` and above from the log watcher by default.

If Pulse or Telescope becomes noisy again, the first question should be whether a success-path diagnostic was promoted above `debug`.

## Change Checklist

When adding or changing logs:

1. Decide whether the event is audit data or operational data.
2. If it is audit data, prefer an existing database record over a file log.
3. If it is operational data, choose the lowest level that still supports action.
4. Do not log full payloads on high-frequency success paths.
5. Do not emit repetitive scheduled-task logs when nothing happened.
6. When a failure already has a structured `error` log, avoid also calling `report()` unless it needs Laravel's exception pipeline.
