# SLTB Fleet Platform Simulation-First Timeline

## Planning Assumptions

| Item | Assumption |
| --- | --- |
| Delivery model | Build on top of the current Laravel platform as one extension-based application, using Filament/Blade plus targeted JS dashboard surfaces instead of a separate baseline React operations app. |
| Simulation backbone already available | The platform already supports synthetic fleet seeding, queued fleet simulation by organization, schema/topic-driven telemetry generation, ingestion pipeline coverage, realtime events, and presence transitions. |
| Simulation limitation | Transport-specific simulation still needs to be built for route progression, ETA drift, stop events, fuel behavior, refuel/theft scenarios, and other bus-domain patterns. |
| AI usage | AI is used heavily for scaffolding, CRUD, test generation, API boilerplate, UI generation, refactors, and documentation. |
| AI productivity impact | Expect a 20% to 30% acceleration on code-heavy work, but little benefit on discovery, approvals, UAT, field validation, or external integrations. |
| Team shape | 1 product/solution lead, 2 to 3 backend engineers, 1 to 2 frontend/full-stack engineers, 1 QA/automation engineer, shared DevOps support. |
| Estimate style | Pilot-first estimate optimized for a simulation-backed SLTB pilot, with real-device production readiness separated into a later stream. |
| Deployment model | Dedicated SLTB deployment from a shared codebase with tenant/client feature gating. |
| Passenger/public phase | Passenger APIs and public/mobile experience remain in scope, but are treated as post-pilot and not pilot-gating. |
| Exclusions | Hardware procurement, on-bus installation, SIM rollout, government approvals, and nationwide field deployment logistics are excluded from the durations below. |

## WBS and Time Estimate

| WBS | Category | Workstream | Key Deliverables | Duration | Indicative Window | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| 0.1 | Customer Specific | Discovery and SLTB solution blueprint | Final SLTB scope mapping, operating model, simulation strategy, route/fuel/maintenance workshops, implementation blueprint | 2 to 3 weeks | W1 to W3 | Locks the pilot boundary and the transport-domain simulation goals early. |
| 1.1 | Core Backend | Extension seams, module boundaries, feature gating | Client-extension pattern, module registration approach, contracts, feature flags, scope isolation | 2 to 3 weeks | W1 to W3 | Keeps SLTB-specific logic out of the reusable product core. |
| 1.2 | Core Backend | Fleet domain and API foundation | Buses, depots, drivers, assignments, device-to-bus mapping, operational status model, `api/v1` baseline | 4 to 5 weeks | W2 to W6 | Forms the shared base for routing, fuel, maintenance, and operator workflows. |
| 1.3 | Core Backend | Transport telemetry normalization | GPS/fuel payload mapping, bus state snapshots, location history, ignition/speed/idle events, realtime fleet event model | 4 to 5 weeks | W3 to W8 | Reuses the current ingestion and realtime infrastructure. |
| 1.4 | Core Backend | SLTB transport simulation layer | Route progression simulator, stop-event generation, ETA variance scenarios, fuel behavior scenarios, incident patterns | 4 to 5 weeks | W3 to W8 | This is the main new accelerator that enables a strong pilot before hardware is fully available. |
| 1.5 | Core Backend | Laravel operations UI and realtime dashboards | Operator pages, control-room dashboards, live fleet views, route compliance views, websocket-driven dashboard surfaces | 4 to 6 weeks | W6 to W12 | Assumes Laravel-first UI with targeted JS components where realtime complexity requires it. |
| 1.6 | Core Backend | Reporting, alerts, exports, and operator analytics | Fleet KPIs, operational reports, CSV/PDF exports, alert/event views, audit-friendly reporting flows | 3 to 4 weeks | W9 to W12 | Built after fleet and telemetry models stabilize. |
| 1.7 | Core Backend | Pilot hardening and operational readiness | Observability, background job scaling, pilot support runbooks, failure recovery, performance validation | 2 to 3 weeks | W14 to W18 | Focused on pilot-grade readiness, not full production hardening. |
| 2.1 | Customer Specific | Route and stop data onboarding | Route model, stop registry, depot mapping, service calendars, SLTB data import templates | 3 to 4 weeks | W5 to W8 | Depends on the fleet base model and the agreed SLTB route data shape. |
| 2.2 | Customer Specific | Route compliance and trip tracking | Route CRUD, trip tracking, deviation detection, stop arrival/departure logic, compliance dashboards | 4 to 5 weeks | W8 to W12 | Major pilot-critical transport workflow. |
| 2.3 | Customer Specific | ETA and route-planning rules | ETA calculations, route progress logic, timing rules, exception handling, rule calibration against simulated scenarios | 4 to 6 weeks | W9 to W14 | Uses the transport simulation layer to tune behavior before real fleets are connected. |
| 2.4 | Customer Specific | Fuel analytics and theft/refuel detection | Fuel calibration model, refuel detection, theft detection, consumption baselines, exception reporting | 4 to 5 weeks | W10 to W14 | Also benefits from scenario-driven simulation before live sensor rollout. |
| 2.5 | Customer Specific | Maintenance workflows | Preventive maintenance, inspection checklists, fault workflows, breakdown management, service alerts | 3 to 4 weeks | W11 to W15 | Can proceed once the fleet master data is stable. |
| 2.6 | Customer Specific | Integrations and deployment adaptations | SLTB integrations, SMS gateway hooks, data exchange jobs, deployment constraints, government cloud adaptations | 4 to 6 weeks | W14 to W20 | External dependencies remain a schedule risk even with simulation in place. |
| 2.7 | Customer Specific | Localization, training, and UAT | Sinhala/Tamil/English content pass, accessibility pass, user manuals, operator training, UAT support | 3 to 4 weeks | W15 to W18 | Explicitly included so the pilot does not slip on operational readiness. |
| 2.8 | Customer Specific | Pilot go-live and stabilization | Simulation-backed pilot deployment, defect triage, tuning, support workflows, SLA alignment, production fixes | 3 to 4 weeks | W18 to W22 | This is the primary milestone for the revised plan. |
| 2.9 | Customer Specific | Real-device validation and rollout readiness | Device-level validation, field calibration, failover drills, real telemetry tuning, rollout checklist, release hardening | 6 to 8 weeks | W22 to W30 | Separated from the pilot because it depends on actual hardware behavior and field conditions. |
| 3.1 | Customer Specific | Passenger APIs and public/mobile experience | Route search, stop search, realtime bus location, ETA endpoints, journey planning UX, public-facing web/mobile release | 4 to 6 weeks | W22 to W28 | Post-pilot phase; not part of the pilot critical path. |

## Category Summary

| Category | Approx. Stream Effort | Calendar Impact | Comment |
| --- | --- | --- | --- |
| Core backend developments | 23 to 31 stream-weeks | Mostly W1 to W18 | Shared product investment plus the transport simulation layer needed for a strong pilot. |
| Customer specific pilot scope | 32 to 41 stream-weeks | Mostly W1 to W22 | Simulation lets this move faster without waiting for hardware to be fully available. |
| Pilot calendar estimate | 18 to 22 weeks | W1 to W22 | Pilot-ready milestone centered on simulated fleets and validated operator workflows. |
| Production readiness calendar estimate | 28 to 34 weeks | W1 to W34 | Includes real-device validation, rollout readiness, and post-pilot hardening. |

## Milestone View

| Milestone | Target Window | Exit Criteria |
| --- | --- | --- |
| Solution blueprint signed off | End of W3 | Scope, architecture, simulation goals, module boundaries, and major integrations are agreed. |
| Simulation-backed fleet backend usable | W8 to W10 | Fleet entities, bus-device mapping, simulation-backed telemetry flows, and baseline APIs are working. |
| Operations dashboards and route compliance usable | W12 to W14 | Realtime dashboards, route compliance, and operator workflows are functioning against simulated fleets. |
| ETA/fuel/maintenance pilot scope usable | W14 to W16 | ETA logic, fuel analytics, and maintenance workflows are usable and testable against scenario data. |
| UAT complete | W17 to W18 | Training, localization, accessibility, and customer acceptance for pilot scope are substantially complete. |
| Pilot go-live | W18 to W22 | Simulation-backed production pilot is stable with monitoring and support in place. |
| Passenger/public beta | W26 to W28 | Route, stop, ETA, and public-facing flows are testable end to end after pilot-critical scope. |
| Real-device production readiness | W28 to W34 | Device-level validation, field tuning, failover, and operational readiness are complete. |

## Risk Adjustment

| Scenario | Calendar Estimate | Main Drivers |
| --- | --- | --- |
| Aggressive AI-assisted pilot | 18 to 22 weeks | Fast decisions, strong scenario design, responsive stakeholders, minimal scope churn |
| More realistic enterprise pilot | 22 to 26 weeks | Approval cycles, route/ETA refinements, UAT feedback churn, external integration lag |
| Production readiness after hardware validation | 28 to 34 weeks | Real device calibration, field behavior, rollout hardening, integration finalization |
| High-friction enterprise path | 34 to 44 weeks | Hardware uncertainty, shifting requirements, poor source data, late deployment constraints |

## Public Interfaces and Delivery Assumptions

| Area | Updated Assumption |
| --- | --- |
| Operations frontend baseline | No separate React operations application is assumed in the baseline estimate. |
| API priority | `api/v1` and integration APIs stay in scope, but are prioritized for Laravel-hosted operator surfaces, realtime dashboards, and partner integrations. |
| Simulator controls | The estimate assumes internal simulator controls for fleet scenario configuration, route progression, stop-event generation, fuel behavior, and ETA variance. |
| Passenger/public APIs | Remain in scope, but are explicitly post-pilot and not pilot-gating. |

## Validation Checklist

| Check | Expected Result |
| --- | --- |
| Existing simulation capabilities are called out clearly | The document explicitly references reusable synthetic fleet seeding, queued fleet simulation, schema-driven payload generation, ingestion coverage, realtime events, and presence validation. |
| Simulation limitations are explicit | The document states that transport-domain simulation still needs to be implemented. |
| Pilot and production readiness are separated | The document distinguishes simulation-backed pilot readiness from real-device production readiness. |
| Baseline estimate is Laravel-first | No WBS item assumes a separate React operations frontend. |
| Passenger scope is not on the pilot critical path | Passenger/public work is clearly marked as post-pilot. |

## Recommendation

| Recommendation | Detail |
| --- | --- |
| Use for planning | Quote this as a pilot-first software timeline with a simulation-backed pilot around month 5 and production readiness around months 7 to 8. |
| Use for budgeting | Separate software delivery, simulation development, and pilot operations from hardware procurement and field deployment budgets. |
| Product strategy | Treat transport simulation and operator workflows as reusable product investment, while isolating SLTB-specific rules and integrations behind extensions. |
