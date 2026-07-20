# Device Profile Onboarding UX Plan

## Goal

Make Device Profile creation feel like a protocol-aware onboarding flow instead of a generic database form.

The profile author should first decide whether the device speaks MQTT or HTTP. That choice should then drive labels, defaults, validation, channel templates, address previews, and parameter transformation fields all the way through the wizard.

## Current State

The current create flow is already a Filament wizard:

1. Identity
2. Protocol
3. Starter channels
4. Review

The domain model is more capable than the form currently exposes:

- `device_profile_versions.protocol_config` supports protocol-specific config.
- `device_channels` supports `transport`, `address`, `http_method`, `qos`, `retain`, `description`, and `options`.
- `profile_parameter_definitions` supports `validation_rules`, `control_ui`, `validation_error_code`, `mutation_expression`, and `default_value`.
- `JsonLogicEvaluator` already supports mutation expressions such as arithmetic, conditionals, comparisons, logical operators, two's complement decoding, and big-endian float decoding.

The UX gap is that the onboarding form only exposes the basic parameter fields and currently normalizes advanced parameter fields to `null`. It also creates starter channels with MQTT defaults even when the selected profile protocol is HTTP.

## UX Principles

1. Protocol choice comes first and controls the rest of the flow.
2. MQTT authors should see broker, topic, QoS, retain, and base-topic language.
3. HTTP authors should see base URL, endpoint path, method, headers, auth, and timeout language.
4. Channels remain protocol-neutral in storage, but the form labels should not force HTTP users to think in MQTT topics.
5. Parameters must include extraction, mutation, validation, defaults, and command UI configuration in one place.
6. Switching protocol should preserve user-entered values where possible, but should not silently leave contradictory starter channels.

## Proposed Wizard

### Step 1: Profile Identity

Purpose: define the catalog identity before any transport-specific choices.

Fields:

| Field | Behavior |
| --- | --- |
| Scope | Existing organization/global selector. |
| Name | Required. Auto-suggests key on blur. |
| Key | Required stable profile key. Keep current allowed characters. |
| Tags | Optional key/value metadata. |

Do not ask for channel or payload details here.

### Step 2: Protocol And Connection

Purpose: make the protocol decision explicit and show only fields relevant to that protocol.

Use a segmented control or prominent select for:

- MQTT
- HTTP

#### MQTT Selected

Show these fields:

| Field | Required | Notes |
| --- | --- | --- |
| Broker host | Yes | Default from `config('iot.mqtt.host')`. |
| Broker port | Yes | Default from `config('iot.mqtt.port')`; validate 1-65535. |
| Use TLS | No | Existing boolean field. |
| Base topic | Yes | Default `device`; preview should show `{base_topic}/{device}/{channel}`. |
| Security mode | Yes | Default to current `username_password`, but allow future certificate mode without changing the wizard structure. |
| Username | Conditional | Show when security mode needs it. |
| Password | Conditional | Show when security mode needs it. |

Preview:

```text
device/{device}/telemetry
```

#### HTTP Selected

Show these fields:

| Field | Required | Notes |
| --- | --- | --- |
| Base URL | Yes | Valid URL. This should be described as the base URL used to resolve HTTP channel addresses. |
| Default telemetry endpoint | Yes | Default `/telemetry`; used to seed the first telemetry channel. |
| Default method | Yes | Default `POST`; allowed `GET`, `POST`, `PUT`, `PATCH`. |
| Headers | No | Key/value editor. |
| Auth type | Yes | `none`, `bearer`, or `basic`, matching the value object. |
| Bearer token | Conditional | Show for bearer auth. |
| Basic username | Conditional | Show for basic auth. |
| Basic password | Conditional | Show for basic auth. |
| Timeout seconds | Yes | Default `30`; validate positive integer. |

Preview:

```text
POST https://example.test/devices/{device}/telemetry
```

HTTP should not show broker host, broker port, base topic, QoS, or retain in this step.

### Step 3: Starter Channel Template

Purpose: let the author choose the initial channel map from protocol-aware templates before editing individual rows.

Default options:

| Template | Channels |
| --- | --- |
| Telemetry only | One publish telemetry channel. |
| Telemetry and state | Telemetry publish plus state publish. |
| Telemetry, command, and ack | Telemetry publish, command subscribe, ack publish. |
| Custom | Start with one blank channel. |

#### MQTT Starter Defaults

| Key | Direction | Purpose | Transport | Address label | Default address | MQTT-only defaults |
| --- | --- | --- | --- | --- | --- | --- |
| `telemetry` | Publish | Telemetry | MQTT | Topic suffix | `telemetry` | QoS `1`, retain `false` |
| `state` | Publish | State | MQTT | Topic suffix | `state` | QoS `1`, retain `true` |
| `command` | Subscribe | Command | MQTT | Topic suffix | `command` | QoS `1`, retain `false` |
| `ack` | Publish | Ack | MQTT | Topic suffix | `ack` | QoS `1`, retain `false` |

#### HTTP Starter Defaults

| Key | Direction | Purpose | Transport | Address label | Default address | HTTP method |
| --- | --- | --- | --- | --- | --- | --- |
| `telemetry` | Publish | Telemetry | HTTP | Endpoint path | `/telemetry` | `POST` |
| `state` | Publish | State | HTTP | Endpoint path | `/state` | `POST` |
| `command` | Subscribe | Command | HTTP | Endpoint path | `/commands` | `POST` |
| `ack` | Publish | Ack | HTTP | Endpoint path | `/acks` | `POST` |

For HTTP, the UI must explain the command channel assumption: a subscribe command channel means the platform can resolve an HTTP request address for command delivery. If the device instead polls the platform for commands, that should become an explicit delivery-mode option before activation.

### Step 4: Channel Details

Purpose: edit the selected starter channels without exposing irrelevant transport fields.

Common fields:

| Field | Notes |
| --- | --- |
| Key | Required stable channel key. |
| Label | Required display label. |
| Direction | `publish` means Device to Platform; `subscribe` means Platform to Device. |
| Purpose | Telemetry, state, event, command, or acknowledgement. |
| Transport | Default from the profile protocol; keep editable only behind an advanced option. |
| Description | Optional, currently supported by `device_channels.description` but not exposed in onboarding. |

MQTT channel fields:

| Field | Behavior |
| --- | --- |
| Topic suffix | Required. Current `address` field, relabeled for MQTT. |
| QoS | Visible and required; 0-2. |
| Retain | Visible. Default based on purpose. |
| Address preview | `{base_topic}/{device}/{topic_suffix}` unless address contains `{device}`. |

HTTP channel fields:

| Field | Behavior |
| --- | --- |
| Endpoint path | Required. Current `address` field, relabeled for HTTP. |
| Method | Visible and required per channel. Save into `device_channels.http_method`. |
| Headers override | Optional advanced field stored in `options`. |
| QoS | Hidden and saved as `0` or ignored for HTTP. |
| Retain | Hidden and saved as `false` for HTTP. |
| Address preview | `{method} {base_url}/devices/{device}{endpoint_path}` unless address contains `{device}`. |

The form should not show one generic helper text such as "Topic suffix, HTTP path, or address template." The label and helper text should change with the selected transport.

### Step 5: Parameters

Purpose: define how each channel payload is read, transformed, validated, stored, and optionally rendered as a control.

Group parameter fields inside each channel as follows.

#### Parameter Identity

| Field | Notes |
| --- | --- |
| Key | Required stable parameter key. |
| Label | Required. |
| Active | Default `true`; expose because the table already stores `is_active`. |
| Sequence | Optional ordering control. |

#### Payload Extraction

| Field | Notes |
| --- | --- |
| JSON path | Required. This is the path used before mutation. |
| Data type | Integer, decimal, boolean, string, or JSON. |
| Category | Measurement, state, command, etc. |
| Unit | Optional engineering unit. |

#### Mutation Expression

This section is currently missing from onboarding and should be added.

Fields:

| Field | Notes |
| --- | --- |
| Mutation enabled | Toggle. When off, save `mutation_expression` as `null`. |
| Mutation preset | Optional recipes: scale, offset, scale plus offset, Celsius to Fahrenheit, kPa to Pa, two's complement, big-endian float, custom JSON Logic. |
| Mutation expression | JSON editor stored as `mutation_expression`, not a string. |
| Sample raw value | Local preview input; not persisted. |
| Mutated preview | Evaluate with existing `JsonLogicEvaluator` using `val` as the raw value. |

Execution order shown in the UI:

```text
payload JSON path -> raw value -> mutation expression -> validation -> telemetry/control value
```

Examples:

Scale raw register by 0.1:

```json
{
  "*": [
    { "var": "val" },
    0.1
  ]
}
```

Celsius to Fahrenheit:

```json
{
  "+": [
    {
      "*": [
        { "var": "val" },
        1.8
      ]
    },
    32
  ]
}
```

kPa to Pa:

```json
{
  "*": [
    { "var": "val" },
    1000
  ]
}
```

The UI should validate that the JSON parses to an object or array before saving. A later implementation can add a server-side rule that calls the evaluator with the sample value and returns a form error when evaluation fails.

#### Validation

Fields:

| Field | Notes |
| --- | --- |
| Required | Existing field. |
| Critical | Existing field. |
| Min | Stored inside `validation_rules.min`. |
| Max | Stored inside `validation_rules.max`. |
| Regex | Stored inside `validation_rules.regex`. |
| Enum values | Stored inside `validation_rules.enum`. |
| Validation error code | Stored in `validation_error_code`. |

Validation should run after mutation so limits apply to the engineering value, not the raw device register.

#### Defaults And Control UI

Fields:

| Field | Notes |
| --- | --- |
| Default value | Stored in `default_value`; useful for command payload templates. |
| Widget type | Optional override stored in `control_ui.widget`; otherwise runtime inference can continue. |
| Slider min/max/step | Stored in `control_ui` for numeric command parameters. |
| Select options | Stored in `control_ui` for enum command parameters. |
| Button value | Stored in `control_ui.button_value` for button-style controls. |

Only show the control UI section by default for command parameters. Keep it collapsed for telemetry/state parameters.

### Step 6: Review And Create Draft

Purpose: make the generated contract understandable before it is persisted.

Show:

1. Profile identity.
2. Selected protocol config summary.
3. Resolved MQTT topic or HTTP request previews for every channel.
4. Parameter count by channel.
5. Mutation expression count by channel.
6. Validation warnings for required fields, missing HTTP methods, invalid endpoint paths, or empty parameter sets.
7. Change summary notes.

The create action should still create a draft profile version. Activation remains a separate lifecycle action.

## Protocol Toggle Behavior

### MQTT To HTTP

When the profile protocol changes from MQTT to HTTP:

1. Preserve the hidden MQTT connection values in form state so switching back restores them.
2. Show HTTP connection fields and require base URL, default telemetry endpoint, method, auth, and timeout.
3. If starter channels are untouched defaults, replace them with HTTP starter defaults.
4. If channels have been edited, show a confirmation choice:
   - Convert default-transport channels to HTTP.
   - Keep existing channels as advanced mixed-transport rows.
5. For converted channels:
   - Change transport to `http`.
   - Convert MQTT topic suffix `telemetry` to endpoint path `/telemetry`.
   - Set method from the HTTP default method.
   - Hide QoS and retain.
   - Save `http_method` per channel.
6. Update previews from topic previews to HTTP request previews.

### HTTP To MQTT

When the profile protocol changes from HTTP to MQTT:

1. Preserve the hidden HTTP connection values in form state so switching back restores them.
2. Show MQTT connection fields and require broker host, broker port, base topic, TLS/security fields.
3. If starter channels are untouched defaults, replace them with MQTT starter defaults.
4. For converted channels:
   - Change transport to `mqtt`.
   - Convert endpoint path `/telemetry` to topic suffix `telemetry`.
   - Clear `http_method`.
   - Show QoS and retain.
5. Update previews from HTTP request previews to MQTT topic previews.

## Implementation Plan

1. Refactor the create wizard into smaller field groups:
   - identity fields,
   - protocol fields,
   - starter template fields,
   - channel fields,
   - parameter field groups,
   - review summary.
2. Add protocol-aware state handlers:
   - protocol change handler,
   - channel transport change handler,
   - starter-template generator.
3. Expose HTTP channel method:
   - add `http_method` to channel rows,
   - save user-selected method instead of forcing `''`,
   - hide or normalize MQTT-only QoS/retain when transport is HTTP.
4. Expose parameter advanced fields:
   - `mutation_expression`,
   - `validation_rules`,
   - `validation_error_code`,
   - `default_value`,
   - `control_ui`,
   - `is_active`,
   - `sequence`.
5. Add mutation preview support:
   - start with client-side JSON validity,
   - add a server-side action that evaluates the sample value through `JsonLogicEvaluator`.
6. Add focused tests:
   - MQTT onboarding creates MQTT protocol config and MQTT starter channels.
   - HTTP onboarding creates HTTP protocol config and HTTP starter channels.
   - HTTP channels persist `http_method`.
   - Parameter mutation expressions persist and are evaluated by the profile telemetry pipeline.
   - Switching protocol updates untouched starter channels without overwriting edited custom rows.

## Acceptance Criteria

1. Creating an MQTT profile never exposes HTTP-only fields unless the user opens advanced mixed transport options.
2. Creating an HTTP profile never exposes MQTT-only broker/topic/QoS/retain fields in the normal path.
3. Switching between MQTT and HTTP updates starter channel defaults, labels, validation, and previews.
4. HTTP channels save per-channel `http_method`.
5. Parameter mutation expressions can be entered, previewed, saved, and later evaluated by the existing runtime.
6. Review step clearly shows the resolved MQTT topics or HTTP requests before creating the draft profile version.
