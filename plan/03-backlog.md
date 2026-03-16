# Backlog (GitHub Projects-ready)

## Project board suggestion
Columns:
- Backlog
- Ready
- In Progress
- In Review
- Done

Labels (recommended):
- `area:db`, `area:filament`, `area:ingestion`, `area:sim`, `area:report`
- `type:story`, `type:task`
- `prio:P0`, `prio:P1`, `prio:P2`

Milestones (phases):
1) Phase 1 — Core Schema + Admin UI (DB + Filament Resources)  
2) Phase 2 — Advanced Admin Features (Schema Editors, Provisioning, Bulk Actions)  
3) Phase 3 — Telemetry Ingestion (Laravel vs Go decision)  
4) Phase 4 — Dashboards & Visualization  
5) Phase 5 — Rules & Device Control  
6) Phase 6 — Simulation & Evaluation  
7) Phase 7 — Project Report

---

## Migration Rehearsal — Local Node-RED First (P0)

Strategy: Prove the migration compatibility layer locally before production changes. Keep Laravel generic, keep legacy normalization in Node-RED, and validate a single hub-backed rehearsal flow end to end.

### MR-1: Local Node-RED rehearsal stack
Story: As a migration engineer, I can run Node-RED with the app stack locally so forwarded legacy traffic can be normalized and replayed safely.

Acceptance:
- Node-RED runs from the repo in Docker beside Laravel and NATS.
- Local engineers can forward traffic into a stable HTTP endpoint on Node-RED.
- Node-RED can publish to the local MQTT broker on NATS.

Sub-tasks:
1. Add `node-red` service to `compose.yaml`
2. Commit versioned Node-RED flow storage under `docker/node-red/data`
3. Expose one local port for editor + HTTP ingress
4. Update local stack scripts and env defaults

### MR-2: Canonical normalized contract
Story: As a migration engineer, I can publish one hub presence event and one telemetry event per child device using a deterministic topic contract.

Acceptance:
- Presence publishes to `devices/{hub_external_id}/presence`
- Child telemetry publishes to schema-resolved topics
- Payloads include `_meta` traceability fields for forwarded legacy identifiers

Sub-tasks:
1. Define rehearsal source-to-hub mapping
2. Define rehearsal child-id-to-topic mapping
3. Return structured HTTP responses from Node-RED for unknown/malformed sources
4. Keep decoding/fan-out logic in Node-RED, not Laravel

### MR-3: Minimal hub-child platform support
Story: As a migration engineer, I can model hubs as real devices with attached child devices so hub health remains visible in the new platform.

Acceptance:
- Devices support a parent-child relationship
- Hubs remain visible as devices
- Children can be grouped under one hub without introducing virtual devices or topology tooling

Sub-tasks:
1. Add parent-device foreign key to `devices`
2. Add Eloquent relationships for parent and child devices
3. Surface parent/child context in the device admin UI
4. Seed one migration rehearsal org with one hub and simple child devices

### MR-4: Local rehearsal validation
Story: As a migration engineer, I can validate that forwarded traffic makes the hub online and persists normalized child telemetry locally.

Acceptance:
- Hub presence transitions to online/offline
- Child telemetry persists through the existing ingestion pipeline
- Rehearsal seeding and ingestion are covered by Pest tests

Sub-tasks:
1. Seed migration rehearsal device types, schemas, and devices
2. Add Pest coverage for seeded hub-child relationships
3. Add Pest coverage for ingestion of normalized child telemetry
4. Use the proven rehearsal flow to drive production hardening later

### MR-5: WITCO tenant onboarding pilot
Story: As a migration engineer, I can onboard WITCO locally by binding normalized vendor source signals to real physical devices so a full tenant can be validated before harder tenants move.

Acceptance:
- WITCO hubs are seeded as real parent devices.
- WITCO customer-facing devices use platform-owned external IDs that represent the physical device, not the raw iMoni peripheral.
- The default local seed contains only the WITCO pilot plus reusable global migration catalog entities, not rehearsal-only peripheral devices.
- Node-RED publishes normalized source topics for WITCO iMoni traffic without creating iMoni-shaped user devices.
- Laravel binds WITCO source slots to physical device parameters through a reusable binding layer.
- Hub presence and bound physical-device telemetry persist through the existing Laravel ingestion pipeline.
- WITCO dashboards are seeded against migrated physical devices using reusable global `state_card` and `state_timeline` widgets.
- State widgets support per-widget label and color mappings so one shared schema can render `OPEN/CLOSED`, `ON/OFF`, or alarm states without tenant-specific widget code.

Sub-tasks:
1. Seed WITCO hubs and physical child devices from legacy mappings
2. Add a `device_signal_bindings` model and resolver for normalized source topics
3. Publish WITCO normalized source topics from Node-RED and bind slot values to physical device parameters in Laravel
4. Skip empty legacy parameter mappings in the first onboarding pass and track them as follow-up cleanup
5. Add Pest coverage for WITCO seeding, source-topic binding resolution, and local ingestion
6. Replace seeded rehearsal-only peripheral catalog entries with global reusable hub and `IMONI Status` catalog entries
7. Seed `Status Dashboard` and `History Dashboard` for WITCO using global state widgets and per-device state mapping overrides

---

## Phase 1 — Core Schema + Admin UI (P0)

Strategy: Build complete vertical slices—each story delivers migration + model + factory/seeder + Filament resource + tests. This allows immediate validation of the data model through the UI.

### US-1: Device Type management (data model + protocol config architecture)
Story: As an org admin, I can define device types so devices can be categorized with type-safe protocol configurations.

**Terminology**:
- `key`: Machine-readable identifier (e.g., `energy_meter_3phase`, `led_actuator_rgb`). Used in code/APIs. Must be kebab-case, alphanumeric + underscore/dash only.
- `name`: Human-readable label (e.g., "3-Phase Energy Meter", "RGB LED Actuator"). Used in UI.
- `default_protocol`: Enum value (`mqtt` or `http`). Determines which protocol config class to use.
- `protocol_config`: JSON column storing serialized protocol-specific configuration objects.

**Protocol Config Architecture**:
- Abstract interface: `App\Domain\IoT\Contracts\ProtocolConfigInterface`
  - Methods: `validate(): bool`, `getTelemetryTopicTemplate(): string`, `getControlTopicTemplate(): ?string`, `toArray(): array`
- MQTT implementation: `App\Domain\IoT\ProtocolConfigs\MqttProtocolConfig`
  - Properties: `broker_host`, `broker_port`, `username`, `password`, `use_tls`, `telemetry_topic_template` (default: `device/:device_uuid/data`), `control_topic_template` (default: `device/:device_uuid/ctrl`), `qos` (default: 1), `retain` (default: false)
- HTTP implementation: `App\Domain\IoT\ProtocolConfigs\HttpProtocolConfig`
  - Properties: `base_url`, `telemetry_endpoint`, `control_endpoint`, `method`, `headers` (array), `auth_type` (enum: none/basic/bearer), `timeout` (default: 30)
- Custom Eloquent cast: `App\Domain\IoT\Casts\ProtocolConfigCast` to serialize/deserialize based on `default_protocol` value

Acceptance:
- Store `key` (unique per org or globally), `name`, `default_protocol`, `protocol_config`.
- Support global catalog entries (organization_id = null) with optional org overrides.
- Enforce unique `key` for global types and unique `(organization_id, key)` for org-specific types.
- Protocol config must be validated against the corresponding protocol class schema.
- Protocol classes must be immutable value objects (readonly properties or no setters).

Sub-tasks:
1. **Protocol config foundation**:
   - Interface: `App\Domain\IoT\Contracts\ProtocolConfigInterface`
   - MQTT config: `App\Domain\IoT\ProtocolConfigs\MqttProtocolConfig` (constructor property promotion, implements interface)
   - HTTP config: `App\Domain\IoT\ProtocolConfigs\HttpProtocolConfig`
   - Eloquent cast: `App\Domain\IoT\Casts\ProtocolConfigCast` (deserializes JSON to correct class based on protocol)
   - Enum: `App\Domain\IoT\Enums\Protocol` (cases: Mqtt, Http)
2. **Database & model**:
   - Migration: `device_types` (with `key`, `name`, `default_protocol` as varchar, `protocol_config` as jsonb)
   - Model: `App\Domain\IoT\Models\DeviceType` with `organization()` relation, casts for `default_protocol` → `Protocol` enum and `protocol_config` → `ProtocolConfigCast`
3. **Seeders & factories**:
   - Factory: create global and org-specific device types with valid protocol configs
   - Seeder: 2 global types (energy_meter_3phase with MQTT, led_actuator_rgb with MQTT), 1 org-specific override
4. **Filament resource**:
   - Resource: `DeviceTypeResource` (Admin panel) with form supporting protocol selection and dynamic config fields
   - Form: protocol select (live) → show MQTT or HTTP fields based on selection
   - Table: key, name, protocol badge, org scope indicator
5. **Tests**:
   - Pest: CRUD operations, org scoping enforcement, global vs org-specific uniqueness
   - Pest: protocol config serialization/deserialization (MQTT and HTTP)
   - Pest: validation failures for invalid protocol configs
6. **Policies & permissions (enum-permission)**:
   - Add `device-types.*` permissions to enum
   - Policy: `DeviceTypePolicy` (viewAny, view, create, update, delete)
   - Seed roles/permissions and add tests for authorization gates

### US-2: Device schema versions with parameter definitions
Story: As an org admin, I can define versioned device schemas with parameter definitions so telemetry structure and validation rules are consistently enforced.

**Terminology**:
- **Device Schema**: A contract blueprint for a device type (e.g., "Energy Meter V1 Contract").
- **Schema Version**: Versioned instance of a schema containing concrete parameter definitions.
- **Parameter Definition**: Incoming telemetry key definition with type, unit, validation rules, and JSON path for extraction.

**Schema Versioning**:
- Schemas belong to a device type.
- Versions are ordered integers; unique `(device_schema_id, version)`.
- Only one "active" version per schema (enforced in app logic).
- Versioning allows contract evolution without breaking existing devices.

**Parameter Validation & Mutation**:
- Each parameter has a `json_path` (e.g., `$.voltage.L1`) to extract values from telemetry payload.
- **`mutation_expression`**: JSON column storing **JsonLogic** expressions for transforming raw values (e.g., divide by 10 for decivolts).
- Validation rules stored as JSON (e.g., `{"min": 0, "max": 500, "type": "numeric"}`).
- Display config stored as JSON (e.g., `{"decimals": 2, "unit_position": "suffix"}`).

Acceptance:
- Store device schemas with name.
- Schema versions with integer version numbers and status.
- Parameter definitions with key, label, data_type, unit, required flag, json_path, **mutation_expression**, validation rules, display config.
- Enforce unique `(device_schema_id, version)` and unique `(device_schema_version_id, key)` for parameters.
- Only one "active" version per schema.

Sub-tasks:
1. **Migrations**:
   - Migration: `device_schemas` (id, device_type_id, name, timestamps, soft delete)
   - Migration: `device_schema_versions` (id, device_schema_id, version, status, notes, timestamps)
   - Migration: `parameter_definitions` (id, device_schema_version_id, key, label, data_type, unit, required, json_path, **mutation_expression**, validation, display, timestamps)
   - Unique constraints and indexes
2. **Models & relationships**:
   - Model: `DeviceSchema` with `deviceType()` and `versions()` relationships
   - Model: `DeviceSchemaVersion` with `schema()` and `parameters()` relationships
   - Model: `ParameterDefinition` with `schemaVersion()` relationship
   - Proper casts for JSON fields (validation, display, **mutation_expression**)
3. **Seeders & factories**:
   - Factory: `DeviceSchemaFactory` and `DeviceSchemaVersionFactory`
   - Factory: `ParameterDefinitionFactory` with realistic validation and **mutation rules** (JsonLogic)
   - Seeder: 2 schema versions (energy meter v1 with 7 parameters, LED actuator v1 with 3 parameters)
   - Sample parameters: V1, V2, V3, I1, I2, I3, E for energy meter
4. **Filament resource**:
   - Resource: `DeviceSchemaResource` with schema CRUD
   - Relation manager: version listing on schema detail page
   - Relation manager: parameter definitions on schema version detail page (with inline creation/editing)
   - Form: parameter repeater with json_path builder, **JsonLogic mutation builder**, validation rule builder
   - Table: version status badges, parameter count
5. **Tests**:
   - Pest: schema creation, version ordering, unique constraints
   - Pest: parameter creation with json_path, **mutation logic**, and validation rules
   - Pest: active version enforcement (one active per schema)
   - Pest: parameter JSON path parsing, **mutation execution**, and validation rule application
6. **Policies & permissions (enum-permission)**:
   - Add `device-schemas.*`, `device-schema-versions.*`, `parameter-definitions.*` permissions to enum
   - Policies: `DeviceSchemaPolicy`, `DeviceSchemaVersionPolicy`, `ParameterDefinitionPolicy`
   - Seed roles/permissions and add tests for authorization gates

### US-3: Derived parameters
Story: As an org admin, I can define derived parameters so the platform can compute additional metrics from base telemetry parameters.

Acceptance:
- Derived definitions reference schema version.
- **expression**: JSON column storing **JsonLogic** expressions referencing transformed parameter keys.
- **dependencies**: JSON array of parameter keys required for the computation.
- Store a safe expression and validate dependencies against existing parameters.

Sub-tasks:
- Migration: `derived_parameter_definitions`
- Model: `DerivedParameterDefinition` with **JsonLogic** evaluation logic
- Seed: sample derived parameters (e.g., heat index, total power)
- Filament relation manager: add to schema version view with **JsonLogic builder**
- Pest tests: expression validation, dependency tracking, calculation correctness

### US-4: Error code catalog & event logging
Story: As an org admin, I can map device error codes and track fault events so ingestion/UI can interpret and log faults consistently.

Acceptance:
- Unique `(device_schema_version_id, code)`.
- Support **parameter-level error mapping**: parameters can reference specific codes.
- **Fault Event Log**: Time-series log of all validation and device errors.

Sub-tasks:
- Migration: `device_error_codes` (with severity, recoverable flag)
- Migration: `device_error_events` (historical log: device_id, error_code, parameter_key, raw_value, occurred_at)
- Model: `DeviceErrorCode` (with `ErrorSeverity` enum)
- Model: `DeviceErrorEvent`
- Seed: sample error codes for energy meter and thermal sensors
- Filament relation manager: add to schema version view
- Pest tests: unique code constraint, severity enum, event logging logic

### US-5: Device registration & identity
Story: As an org admin, I can register devices and pin them to a schema version.

Acceptance:
- Device UUID is stable and unique per org: unique `(organization_id, uuid)`.
- Track `connection_state` + `last_seen_at`.

Sub-tasks:
- Migration: `devices`
- Model: `Device` with `organization()`, `deviceType()`, `schemaVersion()` relations
- Factory + seeder: create 100 simulated devices (metadata only)
- Filament resource: `DeviceResource` with filters (type, status), search, bulk actions
- Pest tests: device registration, UUID uniqueness, tenant isolation

### US-6: Provisioning credentials
Story: As an org admin, I can create MQTT credentials so devices can authenticate to the broker.

Acceptance:
- Credentials tied to a device; support rotation.

Sub-tasks:
- Migration: `device_credentials`
- Model: `DeviceCredential`
- Filament relation manager: view/regenerate credentials (on device edit page)
- Pest tests: credential generation, rotation, password hashing

### US-7: Latest readings snapshot table
Story: As a portal user, I can see the latest readings and validation status without querying time-series storage.

Acceptance:
- One row per device: unique `(device_id)`.
- **raw_payload**: Stores the exact original JSON received from the device.
- **transformed_values**: Stores the processed state (parameters with mutations applied + derived parameters).
- **validation_status**: Enum (`valid`, `invalid`, `warning`).
- **validation_errors**: JSONB mapping of `parameter_key -> error_code`.
- Includes schema version used to parse it.

Sub-tasks:
- Migration: `device_latest_readings` (with `raw_payload`, `transformed_values`, `validation_status`, `validation_errors`)
- Model: `DeviceLatestReading` with `ValidationStatus` enum
- Seed: populate with random telemetry, calculated values, and some sample validation errors
- Filament infolist: display on device view page (show raw, transformed, and error details)
- Pest tests: upsert logic, one row per device constraint, **validation status propagation**

### US-8: Device control definitions, state, and logs
Story: As a portal user, I can send control commands and track desired state for devices.

Acceptance:
- Define allowed commands per schema version with a payload schema and MQTT topic template.
- Store a desired state per device for eventual reconciliation.
- Record command logs with status, timestamps, and errors.

Sub-tasks:
- Migrations: `device_command_definitions`, `device_desired_states`, `device_command_logs`
- Models: `DeviceCommandDefinition`, `DeviceDesiredState`, `DeviceCommandLog`
- Seed: command definitions for LED actuator (on/off/blink)
- Filament relation managers: commands on schema version, command log on device page
- Pest tests: command schema validation, desired state reconciliation, log tracking

---

## Phase 2 — Advanced Admin Features (P1)

Focus: Enhanced UI/UX for complex workflows that weren't essential for basic CRUD.

### US-9: Schema version editor with parameter builder
Story: As an org admin, I can create/edit schema versions with a rich parameter definition editor.

Acceptance:
- Inline parameter/derived parameter/error code editors within schema version form.
- Visual validation rule builder (min/max, regex, enum).
- JSON-path auto-suggest based on payload format.

Sub-tasks:
- Custom Filament form with repeater for parameters
- JSON schema validation preview
- Pest tests: complex schema creation, validation

### US-10: Device provisioning workflow
Story: As an org admin, I can provision devices with a guided wizard.

Acceptance:
- Multi-step wizard: select type → select schema version → configure metadata → generate credentials.
- Auto-generate MQTT credentials with QR code export.
- Bulk import from CSV.

Sub-tasks:
- Filament wizard component
- Credential generation action
- QR code export
- Pest tests: wizard flow, bulk import

### US-11: Bulk actions and data management
Story: As an org admin, I can perform bulk operations on devices.

Acceptance:
- Bulk assign schema version.
- Bulk regenerate credentials.
- Export device list with credentials (CSV).

Sub-tasks:
- Filament bulk actions
- CSV export with credentials
- Pest tests: bulk operations, export format

---

## Phase 3 — Ingestion Decision (P0 gate)
### DR-1: Laravel-only vs Go ingestion
Inputs:
- Telemetry cadence: ~1 message/min/device
- Devices: ~100 simulated + 1 prototype
- Requirements: validation, derive, rules, alerts, control

Output:
- Decide ingestion implementation path while keeping Phase 1 DB schema unchanged.
