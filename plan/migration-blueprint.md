# IoT Platform Migration Blueprint

## Objective

Migrate active customers from the old IoT platform to the new schema-based platform without carrying forward avoidable platform debt.

The migration must:

- keep core ingestion generic
- normalize legacy hub-aggregated traffic before it reaches the new platform
- migrate only the alerting capabilities that are still useful
- split legacy device types by actual behavior where needed
- defer rich digital twin tooling into a separate planning track

## Current Understanding

### Migration facts gathered so far

- The production old platform currently has `539` non-deleted devices across `17` organizations and `30` active device types.
- Customer concentration is high:
  - `Teejay`: `246`
  - `TJ India`: `42`
  - `SriLankan Airlines Limited`: `38`
  - `Textrip`: `26`
  - `WITCO`: `13`
  - `Lankacom`: `11`
- `Teejay` and `TJ India` remain the primary migration-critical tenants, with Teejay heavily concentrated in:
  - `AC Energy Mate`: `96`
  - `IMoni Hub`: `30`
  - `Water Flow and Volume`: `28`
  - `Status`: `25`
  - `Fabric Length`: `14`
  - `Steam meter`: `10`
  - `Stenter`: `10`
- `TJ India` is smaller but still behaviorally important, concentrated in:
  - `Fabric Length`: `13`
  - `Status`: `13`
  - `Stenter`: `13`
  - `IMoni Hub`: `3`
- `AC Energy Mate` is still the largest problematic legacy type:
  - `119` devices
  - `5` distinct calibration sets
  - `6` distinct conditional calibration sets
  - `23` distinct parameter sets
- Virtual devices exist, but are concentrated:
  - `25` total virtual devices
  - `23` are `Stenter`
- Legacy schema variation remains significant:
  - `421` devices have explicit parameter mappings
  - `10` devices have derived parameters
  - `totalisedCount` is still the only derived key currently found in production
- Old alerts are smaller in active operational scope than the local snapshot suggested:
  - `11` devices currently have offline alerts enabled
  - all currently enabled offline alerts belong to `SriLankan Airlines Limited`
  - `64` alert rules exist, `42` are enabled
  - `91` devices are linked to alert rules
  - `18` alerts are currently unresolved
- Legacy hubs receive aggregated telemetry over HTTP.
- Node-RED decodes HEX payloads using manufacturer logic and can fan out messages to individual child devices before publishing to the broker.
- Newer vendor devices already send clean JSON and should be low-risk migrations.

### Strategic decisions already made

- Keep the new platform as the long-term core platform.
- Do not introduce Go services for this migration unless a later bottleneck proves it is necessary.
- Use Node-RED as the compatibility and normalization layer for legacy hub traffic.
- Model vendor transport details as internal source-signal bindings, not as user-facing device identities.
- Preserve hub health in the new platform.
- Treat advanced digital twin tooling as a later product track, not a blocker for migration.
- Treat Teejay-specific dashboard and report requirements as generic reusable capabilities where possible.
- Use `WITCO` as the first tenant onboarding pilot after the generic local rehearsal path is stable, and use it to prove real physical devices plus signal bindings rather than iMoni-shaped platform devices.
- Keep the default local seed focused on the active tenant pilot and reusable global migration catalog entries instead of rehearsal-only peripheral devices.
- Treat boolean and enum dashboard rendering as reusable platform widgets with per-widget state mappings, not as tenant-specific dashboard code.

## Principles

1. Do not migrate the old platform 1:1.
2. Migrate behaviors, not labels.
3. Keep ingestion generic and schema-driven.
4. Normalize legacy payloads at the edge.
5. Keep vendor source identity separate from customer-facing device identity.
6. Separate transport topology from machine/group visualization.
7. Build reusable capabilities before tenant-specific code.
8. Use tenant-scoped extensions only when a requirement cannot be made generic.
9. Prefer customer migration waves over big-bang cutover.

## Scope

### In scope

- legacy device and device type migration
- schema and parameter normalization
- calibration and conditional calibration migration
- derived parameter simplification
- hub and child-device modeling
- signal-binding based source-to-device mapping
- offline alert migration
- generic reports and generic topology-style dashboards
- reusable state-card and state-timeline widgets for status and enum parameters
- staged tenant cutover

### Out of scope for this plan

- full digital twin editor/product
- deprecated alarms domain
- preserving old Grafana-driven virtual device design as-is
- rewriting the platform around Go

## Target Product Boundaries

### Core

- ingestion
- schema validation
- mutations and derived parameters
- telemetry storage
- alerts
- generic reporting
- generic dashboards
- hub health
- device and machine grouping metadata

### Configurable

- device schemas
- report templates
- dashboard templates
- topology/network views
- organization-specific thresholds and settings

### Extension

- tenant-scoped custom renderers only when the requirement cannot be generalized
- feature-gated advanced experiences

## Migration Sequence

1. Build the migration inventory and clustering model.
2. Define the canonical target models.
3. Normalize legacy hub traffic through Node-RED.
4. Map legacy behaviors to target schemas and device types.
5. Build alert parity for hub and device offline monitoring.
6. Migrate generic dashboards and reports.
7. Migrate low-risk tenants first.
8. Migrate Teejay and TJ India after the shared patterns are proven.
9. Plan the next-generation digital twin and topology tooling as a separate roadmap track.

---

## Epic 1: Migration Governance and Inventory

### Goal

Create a reliable migration source of truth before building mappings or cutover scripts.

### User Story 1.1

As a migration lead, I need a trusted inventory of all active customers, devices, device types, alerts, hubs, and virtual devices so that the migration scope is explicit and testable.

### Tasks

- Create a migration inventory export for all active organizations.
- Mark organizations as `critical`, `medium`, `low`, or `demo`.
- Exclude demo organizations from primary migration waves.
- Capture active device counts by organization and device type.
- Capture hub vs child-device vs virtual-device counts.
- Capture alert usage by organization.
- Capture reporting and dashboard usage by organization.
- Capture devices added after the current old database snapshot and merge them into the inventory.

### User Story 1.2

As a migration architect, I need a behavior profile for each legacy device so that I can map legacy variation to target schemas safely.

### Tasks

- Create a per-device behavior profile including:
  - organization
  - old device type
  - parameter signature
  - calibration signature
  - conditional calibration signature
  - derived parameter signature
  - hub/child relationship
  - virtual flag
  - offline alert flag
- Store these profiles in a durable migration worksheet or migration table.
- Add a column for proposed target schema or target device type.
- Add a column for migration decision status.

### Acceptance Criteria

- Every active device has one inventory row.
- Demo organizations are clearly marked and excluded from critical-path planning.
- The team can answer "what do we have?" without re-querying the old platform ad hoc.

---

## Epic 2: Canonical Target Modeling

### Goal

Define the target concepts before writing transformation logic.

### User Story 2.1

As a platform engineer, I need a clear target model for hubs, physical devices, source bindings, and machine groupings so that legacy concepts do not bleed into the new core model.

### Tasks

- Define `Hub` as a real edge device with online/offline state.
- Define `Child Device` as the customer-facing physical device used by schemas, dashboards, alerts, and reports.
- Define a parent-child relationship between hub and child devices.
- Define `Source Signal Binding` as the internal mapping from normalized vendor source locator to a device parameter.
- Explicitly document that source signal bindings are not user-facing devices.
- Define `Machine Group` or equivalent grouping metadata for visualization and reporting.
- Explicitly document that machine groups are not the same as hubs.
- Explicitly document that machine groups are not the same as old virtual devices.

### User Story 2.2

As a schema designer, I need a canonical parameter and mutation model so that legacy JSON structures map cleanly to the new system.

### Tasks

- Define target rules for parameter naming.
- Define target rules for json paths.
- Define target rules for simple mutations.
- Define target rules for conditional mutations using schema JSON logic.
- Define target rules for derived parameters using the simplified new model.
- Define when a variation becomes:
  - a schema version
  - a separate device type
  - a device-level override
- Document naming conventions for target device types created from legacy splits.

### Acceptance Criteria

- The platform team has a written target model for hubs, children, source bindings, and machine groups.
- The migration team has a written decision matrix for parameter, mutation, and derived-parameter mapping.

---

## Epic 3: Behavior Clustering and Legacy Type Splitting

### Goal

Split legacy types by actual runtime behavior instead of copying old labels.

### User Story 3.1

As a migration engineer, I need to cluster legacy devices by behavior so that each target type is internally consistent.

### Tasks

- Cluster devices by:
  - old device type
  - parameter signature
  - calibration signature
  - conditional calibration signature
  - derived parameter signature
- Produce a ranked list of legacy types with the highest variation.
- Identify low-risk legacy types that map cleanly 1:1.
- Identify high-risk legacy types that require splits.

### User Story 3.2

As a migration engineer, I need to resolve `AC Energy Mate` first because it is the largest inconsistent legacy type.

### Tasks

- Build a dedicated analysis sheet for `AC Energy Mate`.
- Group the `106` legacy devices into behavior clusters.
- Compare clusters across customers, especially Teejay and TJ India.
- Identify which clusters can share a new target type.
- Identify which clusters require separate target types.
- Identify whether any cluster can be retired or merged through standardization.
- Produce a proposed target type list for stakeholder review.

### User Story 3.3

As a migration engineer, I need to rationalize virtual devices without reproducing the old maintenance burden.

### Tasks

- Inventory all legacy virtual devices.
- Separate "visual grouping only" from "real operational grouping".
- Map `Stenter` virtual devices to a machine-group model.
- Decide whether any non-Stenter virtual devices should survive as first-class entities.
- Document which legacy virtual-device scenarios are replaced by topology layouts or machine groups.

### Acceptance Criteria

- Each legacy type has a defined migration outcome:
  - direct map
  - split into multiple target types
  - replace with grouping
  - retire
- `AC Energy Mate` has an approved split strategy.

---

## Epic 4: Node-RED Compatibility Layer and Hub Normalization

### Goal

Normalize legacy transport complexity before data enters the new platform.

### User Story 4.1

As an integration engineer, I need Node-RED to turn legacy hub HTTP payloads into canonical source messages so that the new platform receives clean telemetry without learning vendor-specific payload rules.

### Tasks

- Define the canonical inbound topic taxonomy for normalized source telemetry.
- Define the canonical payload shape for normalized source-signal messages.
- Define the canonical hub heartbeat or hub status event.
- Update Node-RED to:
  - receive legacy HTTP payloads
  - decode HEX payloads
  - apply manufacturer decoding
  - split aggregated payloads into normalized source-signal messages
  - attach hub metadata
  - publish normalized messages to the broker

### User Story 4.2

As a platform engineer, I need the new platform to recognize both hub presence and normalized source telemetry so that operational visibility is preserved while customer-facing devices stay vendor-neutral.

### Tasks

- Create hub registration strategy.
- Create customer-facing device identity strategy.
- Create source-signal binding strategy from normalized source topics to device parameters.
- Decide whether source identity is derived from:
  - hub identifier plus peripheral key plus slot
  - manufacturer device key
  - migration mapping table
- Persist `last_seen_at` for hubs.
- Persist parent-child linkage for child devices.
- Resolve normalized source messages to device telemetry through bindings.
- Validate resolved device messages against schema expectations.

### Acceptance Criteria

- One legacy aggregated payload can be traced to:
  - one hub health event
  - multiple bound device telemetry events
- The new platform does not need legacy HEX or manufacturer-specific payload logic in core ingestion.

---

## Epic 5: Alerts and Health Monitoring

### Goal

Migrate only the alerting capabilities that remain useful, with better topology awareness.

### User Story 5.1

As an operator, I need hub offline alerts in the new platform so that I know when aggregated field traffic stops at the edge.

### Tasks

- Define hub expected check-in intervals by hub type or organization.
- Implement hub `last_seen_at` tracking.
- Implement hub online/offline state transitions.
- Implement hub offline alert rules.
- Add alert suppression or deduplication to avoid noise.

### User Story 5.2

As an operator, I need device offline alerts without alert storms when the parent hub is already offline.

### Tasks

- Implement device offline monitoring where needed.
- Add parent-aware suppression:
  - if hub is offline, suppress or group child offline alerts
- Migrate old offline alert flags and frequencies where still meaningful.
- Review whether daily-frequency offline alerts should remain daily or be modernized.

### User Story 5.3

As a migration engineer, I need to leave the deprecated alarms domain behind so that the new platform remains simpler.

### Tasks

- Map required old alert data to the new alert model.
- Ignore deprecated alarms structures.
- Migrate only active alert concepts:
  - offline status
  - recipients
  - channels
  - history if required

### Acceptance Criteria

- Hub offline monitoring works.
- Child offline monitoring works.
- Parent hub outages do not trigger uncontrolled child alert floods.

---

## Epic 6: Generic Reports and Topology Views

### Goal

Promote valuable Teejay scenarios into reusable product capabilities instead of treating them as permanent custom code.

### User Story 6.1

As a product team, I need complex Teejay report scenarios evaluated as generic capabilities so that similar tenants can reuse them later.

### Tasks

- Inventory the Teejay reports that are truly in use.
- Group reports into categories:
  - generic
  - generic with configuration
  - tenant-specific
- Define a common report engine boundary:
  - scheduling
  - permissioning
  - data extraction
  - aggregation
  - export generation
  - delivery
- Define plug points for report-specific dataset builders and templates.

### User Story 6.2

As an operator, I need topology-style views for flow and balance monitoring so that network-style scenarios like water distribution can be monitored generically.

### Tasks

- Define a generic topology view model:
  - topology
  - node
  - edge
  - telemetry binding
  - computed metric
- Model water distribution as the first reference use case.
- Support formulas such as:
  - total input
  - branch consumption
  - total output
  - inferred loss or leakage
- Design the feature as reusable for water, steam, air, or power networks.

### User Story 6.3

As a product owner, I need digital twin planning separated from migration delivery so that the migration is not blocked by a richer future editor.

### Tasks

- Create a separate future workstream for:
  - digital twin editor UX
  - SVG authoring
  - custom widget placement
  - richer live overlays
- Keep the current migration focused on generic topology and dashboard needs.

### Acceptance Criteria

- Teejay reporting and visualization needs are classified into reusable product capabilities where possible.
- A separate future workstream exists for richer digital twin tooling.

---

## Epic 7: Customer Migration Waves

### Goal

Migrate customers in an order that reduces risk and validates patterns before the hardest tenants move.

### User Story 7.1

As a migration lead, I need low-risk tenants migrated first so that the platform and process are proven before Teejay and TJ India.

### Tasks

- Define migration waves.
- Proposed order:
  - Wave 1: local-first onboarding pilot with `WITCO`, followed by low-risk JSON-first or cleanly modeled tenants
  - Wave 2: medium-complexity tenants such as SriLankan, Textrip, and Lankacom
  - Wave 3: TJ India
  - Wave 4: Teejay
- Add entry criteria and exit criteria for each wave.
- Add rollback rules for each wave.

### User Story 7.2

As a migration lead, I need a separate Teejay remediation track so that hard Teejay edge cases do not block the rest of the migration.

### Tasks

- Create a Teejay-specific discovery backlog.
- Keep Teejay remediation separate from the generic migration backbone.
- Reuse proven patterns from earlier waves before finalizing Teejay mappings.

### Acceptance Criteria

- Customer migration order is approved.
- Teejay complexity is isolated instead of slowing the entire program.

---

## Epic 8: Data Migration Tooling

### Goal

Build repeatable migration tooling instead of relying on manual data moves.

### User Story 8.1

As a migration engineer, I need import tooling for organizations, device types, devices, relationships, and alert settings so that migration runs are repeatable.

### Tasks

- Build export routines from the old platform data.
- Build transformation routines for target schemas and target device types.
- Build import routines for:
  - organizations
  - hubs
  - child devices
  - source signal bindings
  - parent-child relations
  - machine groups
  - alert settings
- Build dry-run mode.
- Build idempotent rerun behavior.

### User Story 8.2

As a migration engineer, I need mapping tables between old and new identifiers so that cutover and validation are traceable.

### Tasks

- Create mapping tables for:
  - old organization id -> new organization id
  - old device type id -> target device type id
  - old device id -> new device id
  - old virtual device id -> machine group id or replacement
- Keep legacy IDs out of canonical runtime identity where they are not needed after import.
- Track migration status per entity.
- Record reasons for skipped entities.

### Acceptance Criteria

- Migration scripts can run repeatedly without duplicating entities.
- Every migrated entity is traceable back to the old platform.

---

## Epic 9: Validation, UAT, and Cutover

### Goal

Make migration completion measurable and reversible.

### User Story 9.1

As a QA lead, I need validation checklists for telemetry, alerts, dashboards, and reports so that each migration wave is approved with evidence.

### Tasks

- Define telemetry validation checks:
  - message arrival
  - schema validation
  - mutations
  - derived values
  - storage
- Define alert validation checks:
  - hub offline
  - child offline
  - suppression behavior
- Define dashboard validation checks.
- Define report validation checks.
- Define customer sign-off checklist.

### User Story 9.2

As an operator, I need a staged cutover and rollback path so that tenant migration is low risk.

### Tasks

- Define parallel-run period where needed.
- Define cutover windows per tenant.
- Define rollback triggers.
- Define rollback steps.
- Define post-cutover monitoring windows.

### Acceptance Criteria

- Each wave has a signed validation checklist.
- Each wave has a rollback document.

---

## Epic 10: Post-Migration Productization

### Goal

Convert proven migration patterns into clean reusable product capabilities.

### User Story 10.1

As a product team, I need reusable topology dashboards so that future customers can use them without custom code.

### Tasks

- Convert migrated topology scenarios into configurable templates.
- Remove temporary one-off mappings where possible.
- Document reusable configuration patterns.

### User Story 10.2

As a product team, I need a separate digital twin roadmap so that richer visual tooling can evolve without destabilizing the migrated core.

### Tasks

- Create a future epic for digital twin editor design.
- Define desired capabilities:
  - SVG authoring
  - live widget binding
  - reusable topology components
  - tenant-safe customization
- Keep this roadmap explicitly separate from core migration acceptance.

### Acceptance Criteria

- Reusable capabilities are documented.
- A future digital twin roadmap exists but does not block migration closure.

---

## Initial GitHub Backlog Suggestions

### Program setup

- Create migration inventory workbook
- Create behavior-profile export for all active devices
- Create migration status model and identifier mappings

### High-priority discovery

- Analyze `AC Energy Mate` variation clusters
- Analyze `Stenter` virtual-device replacement strategy
- Analyze Teejay and TJ India schema split candidates
- Inventory active Teejay reports and classify generic vs custom

### Core platform work

- Add hub entity and hub health model
- Add parent-child device relationship support
- Add hub offline alerting
- Add child offline alert suppression when hub is offline
- Add machine-group model for visualization and reporting

### Integration work

- Create local Node-RED rehearsal stack in Docker
- Accept forwarded legacy traffic into local Node-RED over HTTP
- Define canonical Node-RED normalized message contract
- Build Node-RED legacy hub decoder and fan-out flow
- Build hub status event publishing
- Build child telemetry publishing for normalized topics
- Rehearse one hub plus simple child-device behaviors locally before production cutover

### Migration tooling

- Build export and transform tooling for organizations and devices
- Build target schema import tooling
- Build alert settings migration tooling
- Build dry-run validation and rerunnable imports

### Productization

- Design generic topology dashboard model
- Design generic flow-balance report model
- Create separate roadmap item for digital twin editor planning

---

## Risks to Watch

- Treating old device type names as target types without behavior clustering
- Allowing Teejay-specific needs to leak into core shared product code
- Recreating legacy virtual-device complexity instead of replacing it with better grouping models
- Moving HEX and manufacturer parsing into the new platform instead of keeping it in Node-RED
- Migrating alerts without topology-aware suppression
- Attempting Teejay before lower-risk customers validate the migration path

## Definition of Done

The migration program is done when:

- active tenants run on the new platform
- hubs and child devices are visible and monitored
- offline alerts are functioning with sane suppression
- required dashboards and reports are available
- Teejay and TJ India have approved mappings
- legacy virtual-device debt has been replaced with clearer product models
- the old platform is no longer needed for daily operations
