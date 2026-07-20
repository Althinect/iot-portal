# IoT Portal

> Multi-tenant IoT device management platform for monitoring and controlling connected devices

## 🚀 Quick Start

### Prerequisites

- **Docker Desktop** (or Docker Engine + Compose)
- **Git**
- **Composer** 2.x (optional if you use Docker-based Composer install)
- **Node.js** 20+ & **NPM**

### 1. Clone & Install Dependencies

```bash
git clone https://github.com/tharindarodrigo/iot-portal.git
cd iot-portal
```

Install dependencies with one of the following options:

```bash
# Option A: local Composer
composer install
npm install
```

```bash
# Option B: no local Composer (uses Docker)
./scripts/composer-install.sh
npm install
```

### 2. Environment Configuration

```bash
cp .env.example .env
```

Edit `.env` and set your database credentials:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=iot_portal
DB_USERNAME=sail
DB_PASSWORD=password
```

### 3. PostgreSQL & TimescaleDB Setup

The project uses [TimescaleDB](https://www.timescale.com/) for time-series telemetry data.

With Docker/Sail, TimescaleDB is included automatically via `timescale/timescaledb` in `compose.yaml`, so you do not need to install it separately on your host.

If you run PostgreSQL outside Docker, install the extension on your PostgreSQL instance:

```bash
# macOS (Homebrew)
brew install timescaledb

# Then enable it in your postgresql.conf
timescaledb-tune --quiet --yes

# Restart PostgreSQL
brew services restart postgresql@16
```

Verify TimescaleDB is available:

```sql
-- Connect to your database
psql -d iot_portal

-- Check the extension loads
CREATE EXTENSION IF NOT EXISTS timescaledb;
SELECT extversion FROM pg_extension WHERE extname = 'timescaledb';
```

> The migration `2026_02_06_182310_create_device_telemetry_logs_table` automatically enables the extension and creates a hypertable on `device_telemetry_logs.recorded_at` when running on PostgreSQL.

### 4. Start the Platform Services (Recommended)

Start the full Docker platform stack in one command:

```bash
./scripts/platform-up.sh
# or: composer platform:up
```

This starts all required services:
- `laravel.test` (web app)
- `pgsql` (TimescaleDB)
- `redis`
- `nats`
- `mailpit`
- `reverb`
- `iot-listen-states`
- `iot-listen-presence`
- `iot-ingest-telemetry`
- `horizon`
- `scheduler`

After first startup, initialize and seed the database:

```bash
docker compose -f compose.yaml exec laravel.test php artisan key:generate --no-interaction
docker compose -f compose.yaml exec laravel.test php artisan migrate --seed --no-interaction
```

Stop everything:

```bash
./scripts/platform-down.sh
# or: composer platform:down
```

### 5. Database Migration & Seeding

```bash
docker compose -f compose.yaml exec laravel.test php artisan migrate --seed --no-interaction
```

Reset and reseed from scratch (development only):

```bash
docker compose -f compose.yaml exec laravel.test php artisan migrate:fresh --seed --no-interaction
```

This creates all tables, enables TimescaleDB hypertables, syncs permissions, and seeds demo data including:
- Admin user (`admin@admin.com` / password)
- Organizations with devices
- Device types (thermal sensor, smart fan, dimmable light)
- Schema versions with parameter definitions

### 6. Build Frontend Assets

```bash
npm run build

# OR for development with hot reload:
./scripts/platform-up.sh --vite
# or: composer platform:up:vite
```

### 7. Telemetry Queue Workers (Seamless Flow)

Telemetry processing is split into two always-on services:

- `iot-ingest-telemetry` subscribes to NATS and dispatches ingestion jobs to the Redis `ingestion` queue.
- `horizon` runs the queue workers and processes `default`, `ingestion`, and `simulations` queues (as configured in `config/horizon.php`).

Check worker status and queues:

```bash
docker compose -f compose.yaml logs -f iot-ingest-telemetry horizon
```

Scale queue processing when telemetry volume grows:

```bash
docker compose -f compose.yaml up -d --scale horizon=2
```

### 8. Start Services Manually (Alternative, Host Runtime)

If you prefer to run services individually:

Set host-based endpoints in `.env` before using this mode:

```dotenv
DB_HOST=127.0.0.1
REDIS_HOST=127.0.0.1
IOT_NATS_HOST=127.0.0.1
INGESTION_NATS_HOST=127.0.0.1
```

```bash
docker compose -f docker-compose.nats.yml up -d
php artisan reverb:start --port=8090
php artisan iot:listen-for-device-states
php artisan iot:listen-for-device-presence
php artisan iot:ingest-telemetry
php artisan horizon
php artisan schedule:work
```

Optional frontend hot reload:

```bash
npm run dev
```

The NATS configuration is at `docker/nats/nats.conf` and includes:
- JetStream enabled with 256 MB memory / 2 GB file storage
- MQTT support on port 1883
- HTTP monitoring on port 8222 (mapped to host 8223)

### 9. Configure Git Workflow (Contributors)

```bash
./scripts/setup-dev.sh
```

### Access the Application

If using Docker stack:
```
http://localhost:8081
```

> For Docker mode, use `http://localhost:8081` for the web app and `ws://localhost:8090` for Reverb.  
> Do not run host (`php artisan ...`) daemons in parallel with Docker daemons.

For a host-only fallback:
```bash
php artisan serve
```

Default admin login: `admin@admin.com` / `password`

---

## 🔧 Running Services Summary

For full functionality, you need these services running:

| Service | Command | Purpose |
|---------|---------|---------|
| Platform startup (recommended) | `./scripts/platform-up.sh` | Starts full Docker platform including web, broker, listeners, Horizon, and scheduler |
| Platform shutdown | `./scripts/platform-down.sh` | Stops all platform containers |
| Show container status | `docker compose -f compose.yaml ps` | Quick health/status view for all services |
| Telemetry + queue logs | `docker compose -f compose.yaml logs -f iot-ingest-telemetry horizon` | Validate telemetry ingestion and queue worker processing |
| Scale queue workers | `docker compose -f compose.yaml up -d --scale horizon=2` | Add more Horizon processes for high telemetry throughput |
| Vite (optional) | `./scripts/platform-up.sh --vite` | Starts platform and launches Vite dev server inside `laravel.test` |

If dashboard realtime appears stale, rebuild assets in Docker and clear cached config:

```bash
docker compose -f compose.yaml up -d
docker compose -f compose.yaml exec laravel.test php artisan optimize:clear
npm run build
```

---

## 📡 Device Control & Simulation

### Mock Device (Software Simulator)

Simulate a device that subscribes to command topics and responds with state:

```bash
php artisan iot:mock-device
# Interactive — search and select a device, then it listens for commands
```

### Manual State Publish

Publish a state update as if a device sent it:

```bash
php artisan iot:manual-publish
# Interactive — select device, topic, fill parameters, publish to NATS
```

### Physical Device (ESP32)

The project includes ESP32-S3 firmware for a dimmable light demo device at `plan/DeviceControlArchitecture/esp32-dimmable-light/`.

The ESP32 connects via MQTT to the NATS broker on port `1883` and uses topics:
- **Subscribe** (commands): `devices/dimmable-light/{device_id}/control`
- **Publish** (state): `devices/dimmable-light/{device_id}/state`

---

## 🧪 Development

### Running Tests

```bash
# Run all tests
php artisan test --compact

# Run specific test file
php artisan test --compact tests/Feature/DeviceTypeTest.php

# Run with filter
php artisan test --compact --filter=DeviceCommandDispatcher

# Run with coverage
php artisan test --coverage
```

### Code Quality

```bash
# Format code (Laravel Pint)
vendor/bin/pint --dirty --format agent

# Static analysis (PHPStan)
vendor/bin/phpstan analyse

# Run all quality checks (format + analyse)
composer run x      # with auto-fix
composer run x-test # dry-run (CI mode)

# If you don't have local Composer:
docker compose -f compose.yaml exec laravel.test composer run x
docker compose -f compose.yaml exec laravel.test composer run x-test
```

## 🔄 Contributing

This project follows a structured git-flow workflow with automated enforcement.

### Quick Workflow

1. **Start a new feature**
   ```bash
   ./scripts/new-feature.sh
   # Follow the prompts to create your feature branch
   ```

2. **Make your changes**
   ```bash
   # Commits are automatically validated
   git commit -m "US-1: Add ProtocolType enum #1"
   ```

3. **Push and create PR**
   ```bash
   git push origin feature/us-1-device-types
   # Then open a PR on GitHub
   ```

4. **Automated checks**
   - ✅ Commit message format (`US-<number>:` prefix)
   - ✅ Branch naming (`feature/us-<number>-<slug>`)
   - ✅ Issue linking in PR
   - ✅ Tests, Pint, PHPStan

📚 **Full workflow details**: See [CONTRIBUTING.md](CONTRIBUTING.md)

## 📂 Project Structure

```
app/
├── Console/Commands/IoT/  # Artisan commands (listener, mock device, manual publish)
├── Domain/                # Domain logic (DDD bounded contexts)
│   ├── DeviceControl/     # Command dispatching, command logs
│   ├── DeviceManagement/  # Devices, device types, NATS publishing
│   ├── DeviceSchema/      # Schemas, versions, parameters, topics
│   └── Shared/            # Users, organizations
├── Events/                # Broadcast events (CommandDispatched, DeviceStateReceived)
├── Filament/              # Admin panel resources
│   ├── Admin/             # Super admin panel (cross-tenant)
│   └── Portal/            # Organization portal (tenant-aware)
├── Http/                  # Controllers, middleware
└── Policies/              # Authorization policies

database/
├── migrations/            # Schema (includes TimescaleDB hypertables)
├── factories/             # Model factories
└── seeders/               # Demo data seeders

docker/
└── nats/nats.conf         # NATS broker configuration

plan/
├── DeviceControlArchitecture/  # Architecture docs + ESP32 firmware
├── 01-erd-core.md              # Core ERD
├── 02-erd-extension.md         # Extended ERD
└── 03-backlog.md               # User stories backlog

tests/
├── Feature/               # Feature tests
├── Filament/              # Filament-specific tests
└── Unit/                  # Unit tests
```

## 📊 Features

### Phase 1 - Core Schema & Admin UI
- ✅ Multi-tenant organization management
- ✅ Role-based access control (enum-permission)
- ✅ Device type catalog with protocol configs
- ✅ Schema versioning with parameter definitions
- ✅ Device registration and provisioning
- 🔄 Latest readings snapshot

### Phase 2 - Advanced Admin Features
- 📋 Rich schema version editor
- 📋 Guided device provisioning wizard
- 📋 Bulk operations

### Phase 3 - Telemetry Ingestion
- ✅ MQTT/NATS ingestion pipeline (via NATS MQTT bridge)
- ✅ Parameter validation & transformation
- ✅ TimescaleDB hypertable for telemetry logs
- 📋 Derived parameter calculation

### Phase 4 - Dashboards & Visualization
- ✅ Real-time device control dashboard (WebSocket via Reverb)
- ✅ Live message flow visualization
- 📋 Time-series charts
- 📋 Alerts and notifications

### Phase 5 - Device Control
- ✅ Command sending via NATS (downlink)
- ✅ Real-time command lifecycle tracking (dispatched → sent → acknowledged)
- ✅ Device state reception and broadcasting
- ✅ NATS KV store for last known device state

### Phase 6 - Simulation & Evaluation
- ✅ Mock device CLI simulator
- ✅ Manual device publish command
- ✅ ESP32 dimmable light firmware
- 📋 Performance testing
- 📋 Demo data generation

## 🛠 Tech Stack

- **Backend**: Laravel 12, PHP 8.4
- **UI**: Filament 5, Livewire 4, Alpine.js, Tailwind CSS
- **Database**: PostgreSQL 16+ with TimescaleDB (JSONB for flexible schemas, hypertables for telemetry)
- **Messaging**: NATS 2.10 (JetStream + MQTT bridge) via Docker
- **Real-time**: Laravel Reverb (WebSockets), Pusher JS client
- **Queue**: Laravel Horizon (Redis)
- **Testing**: Pest 4
- **Code Quality**: Pint, PHPStan (Level 8), Rector
- **IoT Protocols**: MQTT (via NATS MQTT bridge), NATS (native)

## 📖 Documentation

- [Contributing Guide](CONTRIBUTING.md) - Git workflow, code standards
- [Agent Guidelines](AGENTS.md) - AI coding assistant guidelines
- [Device Control Architecture](plan/DeviceControlArchitecture/01-device-control-flow.md) - Command/state flow design
- [Project Planning](plan/) - ERD, backlog, technical decisions

## 🔐 Security

If you discover a security vulnerability, please email security@example.com. All security vulnerabilities will be promptly addressed.

## 📄 License

This project is licensed under the MIT License.

## 🙏 Acknowledgments

- Laravel & Filament communities
- LMU faculty and contributors

---

**Current Status**: Phase 5 Development — Device Control (see [Backlog](plan/03-backlog.md))
