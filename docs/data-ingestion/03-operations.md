# Data Ingestion Operations

## Runtime Controls

Use these controls from `.env.production`, `.env`, or the Runtime Settings admin page where the key is exposed.

| Environment key | Runtime setting | Default | Effect |
| --- | --- | --- | --- |
| `INGESTION_PIPELINE_BROADCAST_THROTTLE_SECONDS` | `ingestion.pipeline.broadcast_throttle_seconds` | `5` | Minimum seconds between Reverb dashboard broadcasts per device/channel. Set `0` to broadcast every persisted telemetry log. |
| `INGESTION_PIPELINE_HOT_STATE_COALESCE_SECONDS` | `ingestion.pipeline.hot_state_coalesce_seconds` | `1` | Seconds to coalesce NATS KV latest-value writes per device/channel. Set `0` to write every telemetry log. |

The telemetry ingester subject list should stay telemetry-specific. Do not use broad wildcard subjects such as `devices.>` for `INGESTION_NATS_SUBJECT`, because presence and state messages will be routed into the telemetry pipeline and become `channel_not_registered` rejects.

Recommended production shape:

```dotenv
INGESTION_NATS_SUBJECT='devices.*.telemetry,devices.*.*.telemetry,devices.*.*.*.telemetry,migration.source.imoni.*.*.telemetry,migration.source.egravity.*.telemetry'
```

## Health Checks

Run these from the Forge host in `~/iot-portal-docker`.

```bash
docker compose --env-file .env.production -f compose.production.yaml -f compose.forge.yaml ps
docker compose --env-file .env.production -f compose.production.yaml -f compose.forge.yaml exec -T web php artisan horizon:status
docker compose --env-file .env.production -f compose.production.yaml -f compose.forge.yaml exec -T redis redis-cli --scan --pattern 'queues:*' | sort | head -80
```

Check app freshness:

```bash
docker compose --env-file .env.production -f compose.production.yaml -f compose.forge.yaml exec -T web php artisan tinker --execute='echo json_encode([
    "failed_total" => DB::table("failed_jobs")->count(),
    "failed_latest" => DB::table("failed_jobs")->max("failed_at"),
    "devices_online" => \App\Domain\DeviceManagement\Models\Device::query()->where("connection_state", "online")->count(),
    "devices_seen_2m" => \App\Domain\DeviceManagement\Models\Device::query()->where("last_seen_at", ">=", now()->subMinutes(2))->count(),
    "telemetry_logs_5m" => \App\Domain\Telemetry\Models\DeviceTelemetryLog::query()->where("created_at", ">=", now()->subMinutes(5))->count(),
    "latest_telemetry_created_at" => \App\Domain\Telemetry\Models\DeviceTelemetryLog::query()->latest("created_at")->value("created_at")?->toIso8601String(),
    "ingestion_messages_5m" => \App\Domain\DataIngestion\Models\IngestionMessage::query()->where("created_at", ">=", now()->subMinutes(5))->count(),
    "latest_ingestion_created_at" => \App\Domain\DataIngestion\Models\IngestionMessage::query()->latest("created_at")->value("created_at")?->toIso8601String(),
], JSON_PRETTY_PRINT);'
```

Check recent runtime errors:

```bash
for c in iot-portal-docker-ingestion-go-events-1 iot-portal-docker-horizon-1 iot-portal-docker-web-1 iot-portal-docker-iot-ingest-telemetry-1; do
    echo "### $c"
    docker logs --since 10m "$c" 2>&1 | grep -Ei 'error|exception|critical|fatal|failed|LongWait|timeout|runtimeSettings|transformed_values|null value|Processing error' | tail -80 || true
done
```

Check resource usage:

```bash
docker stats --no-stream --format '{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}|{{.MemPerc}}' \
    | grep -E '(web|horizon|ingestion-go-events|iot-ingest-telemetry|pgsql|redis|nats|emqx|reverb|node-red)' \
    | sort
```

## Domain Reject Mapping

`failed_terminal` with `channel_not_registered` means the Go ingester received a subject that did not resolve to a registered profile channel or expandable signal binding.

Group current terminal rejects:

```bash
docker compose --env-file .env.production -f compose.production.yaml -f compose.forge.yaml exec -T pgsql sh -lc 'psql -U "$POSTGRES_USER" "$POSTGRES_DB" -c "
select
    case
        when source_subject like $$devices.%.presence$$ then $$devices.*.presence$$
        when source_subject like $$migration.source.imoni.%$$ then $$migration.source.imoni.*$$
        when source_subject like $$migration.source.%$$ then $$migration.source.*$$
        else split_part(source_subject, $$.$$ , 1)
    end as subject_group,
    count(*) as total,
    max(created_at) as latest
from ingestion_messages
where status = $$failed_terminal$$
group by 1
order by total desc;"'
```

Find iMoni source topics with no active binding:

```bash
docker compose --env-file .env.production -f compose.production.yaml -f compose.forge.yaml exec -T pgsql sh -lc 'psql -U "$POSTGRES_USER" "$POSTGRES_DB" -c "
with failed as (
    select
        replace(source_subject, $$.$$ , $$/$$) as source_topic,
        source_subject,
        count(*) as total,
        max(created_at) as latest
    from ingestion_messages
    where status = $$failed_terminal$$
      and source_subject like $$migration.source.imoni.%$$
    group by 1, 2
),
bindings as (
    select distinct source_topic
    from device_signal_bindings
    where is_active = true
)
select f.source_subject, f.source_topic, f.total, f.latest
from failed f
left join bindings b on b.source_topic = f.source_topic
where b.source_topic is null
order by f.total desc, f.latest desc
limit 40;"'
```

Find bindings whose configured source JSON path does not appear in the live payload:

```bash
docker compose --env-file .env.production -f compose.production.yaml -f compose.forge.yaml exec -T pgsql sh -lc 'psql -U "$POSTGRES_USER" "$POSTGRES_DB" -c "
select dsb.source_topic, dsb.parameter_key, dsb.source_json_path, ppd.json_path as target_json_path, ppd.type
from device_signal_bindings dsb
left join profile_parameter_definitions ppd
    on ppd.device_channel_id = dsb.device_channel_id
   and ppd.key = dsb.parameter_key
where dsb.is_active = true
  and dsb.source_topic like $$migration/source/imoni/%$$
order by dsb.source_topic, dsb.sequence
limit 80;"'
```

Recent findings from the forwarded test traffic:

- `devices.*.presence` rejects were caused by an overly broad VM `INGESTION_NATS_SUBJECT='devices.>,migration.source.>'`. The subject list was narrowed to telemetry-shaped subjects.
- Remaining recent rejects are `migration.source.imoni.*` telemetry topics.
- Most recent iMoni rejects are source topics with no active `device_signal_bindings` row.
- One observed active binding used `source_json_path=$.io_1_value` for `migration/source/imoni/869604063845639/00/telemetry`, but the live payload contained `io_0_value`, `io_2_value`, and other fields instead. That binding needs source-path correction or a profile/data decision.
