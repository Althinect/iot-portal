# Package Extraction Roadmap

## Objective

Extract the reusable IoT core from this application into a standalone package that can be consumed by custom apps, while keeping migration delivery and package extraction as separate workstreams.

The package roadmap must:

- keep runtime and Filament concerns architecturally separate
- allow the current app to remain the first consumer
- keep host-specific auth, tenancy, and panel ownership outside the package
- extract only stable, reusable modules in v1
- avoid blocking the migration program

## Working Position

This roadmap assumes:

- package sources will live under `../plugins`
- the package will eventually move into your enterprise GitHub
- the current Laravel app will consume the package via a Composer path repository first
- the package will start from the Spatie Laravel package skeleton and use Spatie package tools conventions

## Current State of the Local Package Repo

The local package repo currently exists at `../plugins/laravel-iot-core`, but it is still the raw Spatie skeleton.

That means:

- `README.md` still contains placeholder content
- `composer.json` still contains placeholder package metadata
- `configure.php` has not yet been run
- namespaces, provider names, config names, and test scaffolding still need to be customized
- Laravel Boost is not currently included in the package repo

This roadmap assumes the very first action is to finish the skeleton bootstrap before any extraction work starts.

## Guiding Principles

1. Extract stable runtime first, not the whole product.
2. Keep migration and package extraction separate, but aligned.
3. Do not let runtime classes depend on Filament.
4. Let the host app own auth, tenancy, users, organizations, and panel providers.
5. Keep package contracts infrastructure-agnostic.
6. Use the current app as the first proving ground.
7. Delay visualization-heavy and tenant-specific modules until the core stabilizes.

## Proposed Package Shape

### Single package in v1

Create one package first, with internal architectural separation:

- `Runtime`
- `Filament`

### Internal layers

#### Runtime

This layer owns the reusable IoT domain and platform runtime:

- device schemas
- devices and hubs
- parent-child relationships
- presence and last-seen state
- telemetry contracts and services
- ingestion contracts and listeners
- mutations and derived parameter engine
- command/control contracts and services
- certificates and publishing abstractions
- package config, migrations, commands, tests

#### Filament

This layer is optional and should provide only generic admin capabilities on top of the runtime:

- device types
- device schemas and versions
- devices and hubs
- telemetry viewer
- certificate actions
- runtime settings UI for core runtime behavior

The Filament layer must be optional and must not leak into runtime services.

## What Should Stay in the Host App for Now

- auth
- tenancy
- user and organization models
- panel providers
- portal UI
- automation UI
- reporting UI
- topology and digital twin UI
- Teejay-specific or tenant-specific visualization logic
- demo tooling, simulators, and migration-specific scripts

## Recommended Extraction Order

1. `DeviceSchema`
2. `DeviceManagement` core
3. `Telemetry`
4. `DataIngestion`
5. `DeviceControl`

### Not in v1

- `Automation`
- `Reporting`
- `IoTDashboard`
- topology or digital twin tooling
- tenant-specific visualization
- portal panel
- demo seeders
- temporary device tooling

## Naming Decision Before Starting

This should be resolved before bootstrapping the package repo.

### Decisions to make

- final vendor name
- final package name
- final PHP namespace root
- whether the package should use your enterprise naming from day one

### Recommendation

Do not keep coursework-specific naming if this will become the long-term enterprise core.

Choose the final naming before extraction begins so that:

- namespaces do not need to be rewritten later
- enterprise ownership is clear
- host apps can adopt the package without rebranding churn

## Bootstrap Plan

### Goal

Create the package repo and wire it into the current app without yet moving major code.

### User Story

As a platform maintainer, I need a package skeleton and local consumption setup so that we can extract modules incrementally and test them in the current app.

### Tasks

- Follow the package `README.md` bootstrap steps first.
- Create a new private repository in the enterprise GitHub using the Spatie Laravel package skeleton as the starting point.
- Clone the new repository under `../plugins`.
- Run the skeleton configurator.
- Set the package name, vendor, namespace, author, and description.
- Replace any remaining placeholder strings in:
  - `README.md`
  - `composer.json`
  - config files
  - service providers
  - source namespaces
  - tests
- Add the package to the current app as a Composer path repository.
- Require the package from the current app.
- Confirm package auto-discovery and package test setup work before moving any domain code.

### Suggested local workflow

Official Spatie skeleton guidance is to create a repo from the template and then run `php ./configure.php`.

Example local bootstrap flow:

```bash
cd /Users/tharindarodrigo/Herd
mkdir -p ../plugins
cd ../plugins

# Clone the new enterprise repo created from spatie/package-skeleton-laravel
git clone git@github.com:<enterprise>/<package-repo>.git
cd <package-repo>

php ./configure.php
composer install
composer test
```

After running the configurator:

- read through the generated `README.md`
- complete any remaining manual placeholder replacements
- confirm the package service provider and namespace names are correct
- confirm the package name is the final enterprise-facing name you want to live with

### Suggested host app Composer setup

Add a path repository in the current app `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../plugins/<package-repo>"
    }
  ]
}
```

Then require the package from the current app:

```bash
composer require <vendor>/<package-name>:*
```

### Acceptance Criteria

- package repo exists in enterprise GitHub
- package source exists under `../plugins`
- package README bootstrap steps have been completed
- placeholder package metadata has been replaced
- package installs in the current app through a path repository
- package tests run successfully before extraction starts

## Development Tooling Note

### Laravel Boost

Laravel Boost is not currently present in `../plugins/laravel-iot-core/composer.json`.

If you want the package repo to benefit from AI-assisted Laravel workflows, package workbench tooling, and package-focused developer workflows, treat Boost as development tooling rather than a runtime dependency.

### Recommendation

- do not make Laravel Boost part of the package runtime surface
- evaluate adding it as a `require-dev` dependency after the skeleton is configured
- keep the package workbench or local development environment as the place where Boost-related workflows live
- continue to let the host app use Boost independently

---

## Epic 1: Package Foundation

### Goal

Create the technical skeleton and package boundaries before moving runtime code.

### User Story 1.1

As a package maintainer, I need the package skeleton configured correctly so that extraction can proceed without rework.

### Tasks

- Configure package metadata.
- Set up package service provider.
- Add package config files.
- Add package test harness.
- Add static analysis and formatting configuration.
- Add CI workflow placeholders for the package repo.

### User Story 1.2

As a platform architect, I need package boundaries defined before code moves so that app-specific coupling does not get dragged into the package.

### Tasks

- Define `Runtime` namespace tree.
- Define `Filament` namespace tree.
- Define package service providers:
  - core package provider
  - optional default adapters provider
- Define an internal Filament plugin class instead of letting the package own the panel provider.
- Document package-owned versus host-owned responsibilities.

### Acceptance Criteria

- package skeleton is configured
- package namespace and layout are approved
- package ownership boundaries are documented

---

## Epic 2: Runtime Contracts and Host Integration

### Goal

Define the integration surface before extracting services.

### User Story 2.1

As a host app developer, I need clear integration contracts so that the package can work in different applications without hardcoding this app’s user and organization models.

### Tasks

- Define config entries such as:
  - package tenant model
  - package actor model
  - tenancy foreign key
  - auth user key
  - Filament enabled flag
- Replace package foreign keys to host `users` and `organizations` with scalar indexed columns where appropriate.
- Decide which compatibility migrations stay in the host app.

### User Story 2.2

As a package maintainer, I need stable infrastructure contracts so that the runtime can support different adapters later.

### Tasks

- Define contracts for:
  - command publishing
  - device state storage
  - hot-state storage
  - analytics publishing
  - presence evaluation
  - telemetry persistence
- Identify which current implementations can move as default adapters.
- Keep adapter registration behind provider bindings.

### Acceptance Criteria

- the host integration surface is documented
- runtime services depend on contracts instead of app-specific classes

---

## Epic 3: Extract Device Schema Module

### Goal

Move the schema engine first because it is foundational and relatively stable.

### User Story

As a platform engineer, I need the schema engine inside the package so that device definitions, parameter metadata, and schema validation live in the reusable core.

### Tasks

- Extract device schema models and enums.
- Extract schema version and topic models.
- Extract parameter definition logic.
- Remove Filament-specific presentation concerns from runtime enums and models.
- Add package tests for schema behaviors.
- Keep host app resources pointing to package runtime classes.

### Acceptance Criteria

- device schema runtime works from the package
- schema tests run inside the package
- host app still functions using package classes

---

## Epic 4: Extract Device Management Core

### Goal

Move generic device, hub, presence, and relationship logic into the package.

### User Story

As a platform engineer, I need generic device and hub domain primitives in the package so that all future apps share the same inventory and topology foundation.

### Tasks

- Extract device and hub runtime models or aggregates.
- Extract parent-child relationship logic.
- Extract certificate and publishing abstractions where they are generic.
- Extract presence and last-seen logic.
- Extract generic device registration and lookup services.
- Keep host-specific policies and panel ownership in the app.

### Acceptance Criteria

- devices and hubs can be managed through package runtime classes
- host app still controls auth, permissions, and UI composition

---

## Epic 5: Extract Telemetry Module

### Goal

Move telemetry contracts, persistence, and read models into the package.

### User Story

As a platform engineer, I need telemetry storage and retrieval in the package so that normalized device data can be handled consistently across apps.

### Tasks

- Extract telemetry models and DTOs.
- Extract telemetry persistence services.
- Extract telemetry validation hooks that depend on package schemas.
- Extract package tests around telemetry persistence.
- Keep visualization-specific query layers outside the package in v1.

### Acceptance Criteria

- package owns telemetry runtime
- visualization-specific layers remain app-owned

---

## Epic 6: Extract Data Ingestion Module

### Goal

Move normalized ingestion runtime into the package after schema and telemetry are stable.

### User Story

As a platform engineer, I need normalized ingestion runtime in the package so that multiple host apps can accept broker messages using the same core logic.

### Tasks

- Extract ingestion DTOs.
- Extract ingestion services and listeners.
- Extract stage logging and persistence logic where generic.
- Extract runtime settings dependencies in a package-safe way.
- Move current NATS, MQTT, and hot-state adapters into optional default adapters if they are stable enough.
- Add integration tests for normalized ingestion.

### Acceptance Criteria

- the package can ingest normalized messages independently of app-specific code
- host app can still override adapters

---

## Epic 7: Extract Device Control Runtime

### Goal

Move device control only after ingestion and telemetry contracts have stabilized.

### User Story

As a platform engineer, I need generic command dispatch and device control runtime in the package so that future apps can issue commands consistently.

### Tasks

- Extract command log models and DTOs.
- Extract command dispatch services.
- Extract command publisher contracts and default adapters.
- Keep custom dashboards and app-specific control UX outside the package.

### Acceptance Criteria

- device control runtime is reusable
- control UX remains optional and host-owned

---

## Epic 8: Build the Internal Filament Plugin

### Goal

Provide a generic admin layer without letting Filament leak into runtime.

### User Story

As a host app developer, I need an optional Filament plugin for the extracted runtime modules so that admin UIs can be enabled without the package owning the whole panel.

### Tasks

- Create a package Filament plugin class.
- Register only generic admin resources and pages.
- Move package-specific resource logic under `src/Filament`.
- Keep runtime enums and models free of Filament contracts.
- Keep panel provider registration in the host app.

### Initial V1 plugin scope

- device types
- device schemas and versions
- devices and hubs
- telemetry logs and viewer
- certificate actions
- runtime settings UI for core runtime behavior

### Explicitly excluded in v1

- automation UI
- reporting UI
- topology or digital twin UI
- portal panel
- customer-specific dashboard builders

### Acceptance Criteria

- the host app can enable the plugin explicitly
- runtime remains independent from Filament

---

## Epic 9: Host App Adoption and Backward Compatibility

### Goal

Make the current app the first consumer without breaking delivery.

### User Story

As a maintainer, I need the current app to consume extracted package modules incrementally so that package extraction can happen safely alongside product work.

### Tasks

- Switch one domain at a time from app classes to package classes.
- Keep current command names and config names where practical.
- Add compatibility shims only when needed.
- Add smoke tests proving the host app can boot and use the package.
- Keep host-only modules in place until their package boundaries are clear.

### Acceptance Criteria

- the current app consumes the package incrementally
- the extraction order does not force a big-bang rewrite

---

## Epic 10: Deferred Modules and Future Split Strategy

### Goal

Prevent premature packaging of unstable or app-specific modules.

### User Story

As a platform architect, I need a clear list of deferred modules so that the v1 package stays focused and stable.

### Deferred from v1

- automation runtime and UI
- reporting runtime and UI
- topology dashboards
- digital twin editor and renderer
- Teejay-specific customizations
- portal resources
- migration-specific tooling
- demo tooling

### Future split candidates

- separate Filament plugin package
- topology visualization package
- reporting package
- automation package

### Acceptance Criteria

- v1 package scope is explicit
- future package candidates are identified but not started prematurely

---

## Suggested Initial GitHub Backlog

### Bootstrap

- decide enterprise package name and namespace
- create enterprise repo from Spatie Laravel package skeleton
- clone package into `../plugins`
- run skeleton configurator
- wire current app to package via path repository

### Foundation

- create package service provider structure
- define runtime and Filament internal namespaces
- define host integration config
- define default adapter provider strategy

### Core extraction

- extract `DeviceSchema`
- extract `DeviceManagement` core
- extract `Telemetry`
- extract `DataIngestion`
- extract `DeviceControl`

### Filament

- create internal Filament plugin
- move generic admin resources into package
- keep panel ownership in host app

### Adoption

- convert current app to consume package modules incrementally
- add package and host smoke tests
- document deferred modules and non-goals

---

## Risks to Watch

- extracting app-specific code into the package too early
- letting Filament contracts leak into runtime
- packaging reporting and visualization before their abstractions stabilize
- tying package migrations directly to current app user and organization tables
- blocking migration delivery on package extraction work
- choosing temporary coursework-oriented naming and needing to rename later

## References

- Spatie Laravel package skeleton:
  [spatie/package-skeleton-laravel](https://github.com/spatie/package-skeleton-laravel)
- Spatie Laravel package tools:
  [spatie/laravel-package-tools](https://github.com/spatie/laravel-package-tools)

## Definition of Done

This package extraction track is successful when:

- a new enterprise-owned package exists under `../plugins`
- the current app consumes it through Composer path repositories
- runtime and Filament are architecturally separated
- `DeviceSchema`, `DeviceManagement`, `Telemetry`, `DataIngestion`, and `DeviceControl` runtime are package-owned
- the host app still owns auth, tenancy, panel composition, and tenant-specific UI
- unstable modules remain outside the package until their boundaries are proven
