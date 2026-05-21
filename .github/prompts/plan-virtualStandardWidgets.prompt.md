## Plan: Virtual Standard Widgets

Treat each standard as a virtual `Device` record, but do not overload the existing physical `parent_device_id` hierarchy for composition. Instead, add a purpose-aware virtual-device attachment model so one primary virtual device can bind multiple physical devices (for example `status`, `energy`, `length`) and then extend the dashboard layer to consume named multi-source bindings. Build the first version with live/hybrid resolution, seed/import the legacy mappings, and only precompute derived telemetry where parity or performance makes it necessary.

**Steps**
1. **Phase 1 — Lock the domain shape** (*blocks everything else*): keep standards in the existing `devices` table as virtual devices, backed by a dedicated virtual `DeviceType` and clear metadata (for example `is_virtual`, template/profile key, shift-calendar key). Preserve `Device::parentDevice()` / `childDevices()` for physical hub/peripheral relationships only, and add a separate virtual composition model/table for `virtual_device_id -> physical_device_id` links with `purpose`, `sequence`, and per-link metadata.
2. **Phase 2 — Add purpose-aware virtual-device links** (*depends on 1*): introduce a dedicated relation such as `VirtualDeviceSource` / `DeviceComposition` instead of reusing `parent_device_id`, because the standard must attach multiple physical devices under one primary virtual device with semantic meaning. Make the model flexible enough to allow multiple physical devices for a purpose later, even if the first rollout mainly uses three purposes.
3. **Phase 3 — Define how standards are configured** (*depends on 1; parallel with 2 once the shape is agreed*): represent the expected source purposes, allowed device types, and shift calendar as a standard profile/blueprint. Recommended starting point: keep the profile close to the virtual device type (metadata/config service) so the team can seed/import legacy mappings first and add admin maintenance second without creating a whole new top-level business entity.
4. **Phase 4 — Extend device management UX** (*depends on 2 and 3*): update the admin-side device form and related services so virtual devices can be created/edited, physical devices can be attached by purpose, and seed/import data can be reconciled later by admins. Keep vendor-portal/device-vendor access concerns out of scope for this pass.
5. **Phase 5 — Introduce a widget source-binding model** (*depends on 2; parallel with 4 after the composition model exists*): keep `IoTDashboardWidget` backward-compatible for simple widgets, but add a richer widget-source abstraction so a widget can resolve from named scopes (`primary`, `status`, `energy`, `length`) or aggregate collections. This should be a dedicated persistent model/table rather than raw ad-hoc JSON so validation, seeding, and admin editing stay manageable.
6. **Phase 6 — Extend current widget resolvers first** (*depends on 5*): evolve the existing `LineChart` and `BarChart` resolvers to support named sources and multi-device aggregation before inventing new widget families. Reuse the current resolver contracts and return shapes so existing dashboard rendering remains stable.
7. **Phase 7 — Add typed replacements for the legacy HTML widgets** (*depends on 5 and 6*): do not port Grafana HTML/JS directly. Instead, translate the attached widget’s semantics into first-party typed widgets: latest snapshot metrics, shift/day/month deltas, state-duration utilization, mini-history, and online/offline state. Recommended family split: a reusable metric-summary card family plus a utilization-summary card family with configurable shift calendars.
8. **Phase 8 — Seed/import legacy virtual-device mappings and default dashboards** (*depends on 3, 4, 6, and 7*): create a seeder/import flow that creates the virtual device, attaches the underlying physical devices by purpose, and seeds the default dashboard widgets for that standard profile. Keep this idempotent so legacy re-imports and dashboard template updates are safe.
9. **Phase 9 — Keep the data strategy hybrid** (*depends on 6 and 7; can be phased*): start with live resolver-based composition from physical-device telemetry, then add derived/precomputed telemetry only for calculations that are expensive or reused heavily (for example utilization windows or period-baseline deltas). Define the promotion rule up front so the implementation team knows when to stop querying live data.
10. **Phase 10 — Verify both parity and backward compatibility** (*depends on 5–9*): add resolver-level Pest coverage for multi-source widgets, idempotency tests for virtual-device mapping/seeders, and regression tests proving single-device widgets still work with the old `device_id` + `schema_version_topic_id` path. Finish with formatting/static analysis and targeted manual dashboard validation against one Stenter-style example.

**Relevant files**
- `/Users/tharindarodrigo/Herd/iot-portal/app/Domain/DeviceManagement/Models/Device.php` — preserve `parentDevice()` / `childDevices()` for physical hierarchy and add virtual composition relations separately.
- `/Users/tharindarodrigo/Herd/iot-portal/app/Filament/Admin/Resources/DeviceManagement/Devices/Schemas/DeviceForm.php` — extend admin UX for creating virtual devices and attaching physical devices by purpose.
- `/Users/tharindarodrigo/Herd/iot-portal/app/Domain/IoTDashboard/Models/IoTDashboardWidget.php` — current single-device/topic binding that needs a backward-compatible richer source model.
- `/Users/tharindarodrigo/Herd/iot-portal/app/Domain/IoTDashboard/Application/WidgetRegistry.php` — register any new typed widget definitions while preserving existing ones.
- `/Users/tharindarodrigo/Herd/iot-portal/app/Domain/IoTDashboard/Contracts/WidgetDefinition.php` — keep new widget families on the current definition/snapshot contract.
- `/Users/tharindarodrigo/Herd/iot-portal/app/Domain/IoTDashboard/Widgets/LineChart/LineChartSnapshotResolver.php` — first extension point for named multi-source line overlays.
- `/Users/tharindarodrigo/Herd/iot-portal/app/Domain/IoTDashboard/Widgets/StatusSummary/StatusSummarySnapshotResolver.php` — reference for latest-snapshot metric tiles and threshold coloring.
- `/Users/tharindarodrigo/Herd/iot-portal/app/Filament/Admin/Pages/IoTDashboard.php` — current widget create/edit flow that assumes one resolved device/topic pair.
- `/Users/tharindarodrigo/Herd/iot-portal/database/seeders/TextripDashboardSeeder.php` — concrete dashboard/widget seeding pattern to reuse for standard dashboard seeders.
- `/Users/tharindarodrigo/Herd/iot-portal/plan/07-teejay-widget-template-replication-plan.md` — already identifies Stenter and related Teejay widgets as multi-source/virtual-device work.
- `/Users/tharindarodrigo/Herd/iot-portal/plan/06-teejay-migration-seeder-plan.md` — reference for modular, idempotent seeding and device-type-oriented migration structure.

**Verification**
1. Add Pest resolver tests for multi-source `LineChart`, aggregate `BarChart`, and the new typed summary/utilization widgets, covering latest values, period deltas, and state-duration calculations.
2. Add seeder/import tests proving a virtual device can be created, linked to physical devices by purpose, reseeded idempotently, and surfaced in dashboard widget source resolution.
3. Add regression tests proving legacy single-device widgets still resolve through `IoTDashboardWidget.device_id` and `schema_version_topic_id` without requiring the new source table.
4. Run targeted dashboard tests for a Stenter-style standard with `status`, `energy`, and `length` sources, then run formatting/static analysis (`vendor/bin/pint --dirty --format agent` and `composer x`) before finalizing implementation.
5. Manually validate one migrated standard dashboard against the legacy widget semantics in the attached Grafana JSON: online/offline badge, utilization calculations, shift naming, and monthly/current/previous shift counters.

**Decisions**
- Store standards as virtual rows in `devices`, not as a new top-level `standards` table.
- Use a primary virtual device plus linked physical devices by purpose.
- Manage mappings through both seed/import flows and admin maintenance.
- Start with a hybrid data strategy: live composition first, derived telemetry only where needed.
- Keep vendor portal / device-vendor access and role-specific permissions out of scope for this planning slice.
- Do not copy raw Grafana HTML/JS into the new platform; port the data semantics into typed widget definitions.

**Further Considerations**
1. Prefer semantic purposes (`status`, `energy`, `length`) over raw device-type names in the composition and widget-source models so widget configs stay reusable even when underlying device types change.
2. If many standard families are expected beyond Stenter, consider promoting the profile/blueprint from metadata/config into a dedicated persistent model later; for the first implementation pass, metadata/config is the leaner path.
3. If live multi-source resolvers need large telemetry scans for utilization or counter deltas, introduce reusable calculators/services first, then only persist derived telemetry after measuring real pressure points.