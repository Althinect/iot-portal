# Device Control Module - Artisan Commands

## Overview

The Device Control module provides seven artisan commands under the `iot:` namespace. Two are long-running listeners, two are scheduled maintenance tasks, and three are development/testing utilities.

| Command | Type | Runs As |
| --- | --- | --- |
| `iot:listen-for-device-states` | Listener | Long-running process |
| `iot:listen-for-device-presence` | Listener | Long-running process |
| `iot:expire-stale-commands` | Maintenance | Scheduled minute |
| `iot:check-device-health` | Maintenance | Scheduled minute |
| `iot:mock-device` | Development | Manual, interactive |
| `iot:simulate` | Development | Manual |
| `iot:manual-publish` | Development | Manual, interactive |

Telemetry ingestion is handled by the Go telemetry ingester in `services/telemetry-ingester`; the legacy Laravel `iot:ingest-telemetry` command has been removed.

## Long-Running Listeners

These commands connect to NATS and process messages indefinitely. Run them under a process supervisor in deployed environments.

### `iot:listen-for-device-states`

Receives NATS state messages and feeds them to `DeviceFeedbackReconciler` for command matching and state storage.

```bash
php artisan iot:listen-for-device-states --host=emqx --port=4222
```

### `iot:listen-for-device-presence`

Receives device presence messages and updates each device's `connection_state`.

```bash
php artisan iot:listen-for-device-presence --host=emqx --port=4222
```

## Scheduled Maintenance

### `iot:expire-stale-commands`

Finds command logs stuck in a non-terminal state and marks them timed out.

```bash
php artisan iot:expire-stale-commands
```

### `iot:check-device-health`

Marks devices offline when they have not communicated within the heartbeat timeout window.

```bash
php artisan iot:check-device-health
```

## Development And Testing Utilities

### `iot:mock-device`

Simulates an IoT device by subscribing to command topics and publishing state responses.

```bash
php artisan iot:mock-device
```

### `iot:simulate`

Publishes simulated telemetry data for a device.

```bash
php artisan iot:simulate {device_uuid}
```

### `iot:manual-publish`

Interactively builds and publishes a state message as if the device sent it.

```bash
php artisan iot:manual-publish
```

## Command Registration

Commands in `app/Console/Commands/IoT/` are auto-discovered by Laravel.
