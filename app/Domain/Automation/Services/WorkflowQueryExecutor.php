<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

class WorkflowQueryExecutor
{
    private const QUERY_TIMEOUT_MS = 3000;

    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $executionContext
     * @return array{
     *     value: int|float,
     *     row: array<string, mixed>,
     *     window: array{start: string, end: string, size: int, unit: string},
     *     sources: array<int, array<string, mixed>>,
     *     sql: string
     * }
     */
    public function execute(AutomationRun $run, array $config, array $executionContext): array
    {
        $validatedConfig = $this->validateConfig((int) $run->organization_id, $config);
        $window = $validatedConfig['window'];
        $sql = $validatedConfig['sql'];
        $sourceDefinitions = $validatedConfig['source_definitions'];

        $windowEnd = $this->resolveWindowEnd($executionContext);
        $windowStart = $this->resolveWindowStart($windowEnd, $window['size'], $window['unit']);

        [$cteSql, $bindings] = $this->buildSourceCteSql($sourceDefinitions, $windowStart, $windowEnd);

        $querySql = <<<SQL
{$cteSql},
__query_result AS (
{$sql}
)
SELECT * FROM __query_result
SQL;

        $rows = $this->databaseManager->connection()->transaction(function () use ($querySql, $bindings): array {
            $connection = $this->databaseManager->connection();

            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('SET LOCAL statement_timeout = '.self::QUERY_TIMEOUT_MS);
            }

            /** @var array<int, object> $results */
            $results = $connection->select($querySql, $bindings);

            return $results;
        });

        if (count($rows) !== 1) {
            throw new RuntimeException('Query node must return exactly one row.');
        }

        $firstRow = (array) $rows[0];

        if (! array_key_exists('value', $firstRow) || ! is_numeric($firstRow['value'])) {
            throw new RuntimeException('Query node result must include numeric [value] column.');
        }

        $resolvedValue = $this->resolveNumericValue($firstRow['value']);

        return [
            'value' => $resolvedValue,
            'row' => $this->normalizeRow($firstRow),
            'window' => [
                'start' => $windowStart->toIso8601String(),
                'end' => $windowEnd->toIso8601String(),
                'size' => $window['size'],
                'unit' => $window['unit'],
            ],
            'sources' => array_map(static function (array $sourceDefinition): array {
                return [
                    'alias' => $sourceDefinition['alias'],
                    'device_id' => $sourceDefinition['device_id'],
                    'device_channel_id' => $sourceDefinition['device_channel_id'],
                    'channel_key' => $sourceDefinition['channel_key'],
                    'parameter_key' => $sourceDefinition['parameter_key'],
                    'parameter_path' => $sourceDefinition['parameter_path'],
                ];
            }, $sourceDefinitions),
            'sql' => $sql,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{
     *     mode: string,
     *     window: array{size: int, unit: string},
     *     sources: array<int, array{alias: string, device_id: int, device_channel_id: int, parameter_key: string}>,
     *     source_definitions: array<int, array{
     *         alias: string,
     *         device_id: int,
     *         device_channel_id: int,
     *         channel_key: string,
     *         parameter_key: string,
     *         parameter_path: string
     *     }>,
     *     sql: string
     * }
     */
    public function validateConfig(int $organizationId, array $config): array
    {
        $mode = $config['mode'] ?? null;

        if ($mode !== 'sql') {
            throw new RuntimeException('Query node must use sql mode.');
        }

        $window = $this->resolveWindow($config['window'] ?? null);
        $sources = $this->resolveSources($config['sources'] ?? null);
        $sqlValue = $config['sql'] ?? null;
        $sql = $this->validateSql(is_string($sqlValue) ? $sqlValue : '');
        $sourceDefinitions = $this->resolveSourceDefinitions($organizationId, $sources);

        return [
            'mode' => 'sql',
            'window' => $window,
            'sources' => $sources,
            'source_definitions' => $sourceDefinitions,
            'sql' => $sql,
        ];
    }

    public function validateSql(string $sql): string
    {
        $normalized = trim($sql);

        if ($normalized === '') {
            throw new RuntimeException('Query SQL is required.');
        }

        $normalized = preg_replace('/;+\s*$/', '', $normalized);
        $resolvedSql = is_string($normalized) ? trim($normalized) : '';

        if ($resolvedSql === '') {
            throw new RuntimeException('Query SQL is required.');
        }

        if (str_contains($resolvedSql, ';')) {
            throw new RuntimeException('Query SQL must be a single statement.');
        }

        if (! preg_match('/^\s*(select|with)\b/i', $resolvedSql)) {
            throw new RuntimeException('Query SQL must start with SELECT or WITH.');
        }

        $forbiddenKeywords = [
            'insert',
            'update',
            'delete',
            'truncate',
            'alter',
            'drop',
            'create',
            'grant',
            'revoke',
            'comment',
            'copy',
            'vacuum',
            'analyze',
            'execute',
            'call',
            'set',
        ];

        foreach ($forbiddenKeywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $resolvedSql)) {
                throw new RuntimeException("Query SQL contains forbidden keyword [{$keyword}].");
            }
        }

        return $resolvedSql;
    }

    /**
     * @return array{size: int, unit: string}
     */
    private function resolveWindow(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Query node requires a valid window configuration.');
        }

        $size = $this->resolvePositiveInt($value['size'] ?? null);
        $unit = $value['unit'] ?? null;

        if ($size === null || ! is_string($unit) || ! in_array($unit, ['minute', 'hour', 'day'], true)) {
            throw new RuntimeException('Query node window must include positive size and valid unit.');
        }

        return [
            'size' => $size,
            'unit' => $unit,
        ];
    }

    /**
     * @return array<int, array{alias: string, device_id: int, device_channel_id: int, parameter_key: string}>
     */
    private function resolveSources(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw new RuntimeException('Query node requires at least one source.');
        }

        $resolved = [];
        $aliases = [];

        foreach ($value as $source) {
            if (! is_array($source)) {
                throw new RuntimeException('Query node source entries must be objects.');
            }

            $alias = $source['alias'] ?? null;
            $deviceId = $this->resolvePositiveInt($source['device_id'] ?? null);
            $channelId = $this->resolvePositiveInt($source['device_channel_id'] ?? $source['channel_id'] ?? null);
            $parameterKey = $this->resolveNonEmptyString($source['parameter_key'] ?? null);

            if (! is_string($alias) || ! preg_match('/^[a-z][a-z0-9_]{0,30}$/i', $alias)) {
                throw new RuntimeException('Query node source alias is invalid.');
            }

            $normalizedAlias = strtolower($alias);

            if (isset($aliases[$normalizedAlias])) {
                throw new RuntimeException("Query node source alias [{$alias}] must be unique.");
            }

            if ($deviceId === null || $channelId === null || $parameterKey === null) {
                throw new RuntimeException("Query node source [{$alias}] is missing required ids.");
            }

            $aliases[$normalizedAlias] = true;

            $resolved[] = [
                'alias' => $normalizedAlias,
                'device_id' => $deviceId,
                'device_channel_id' => $channelId,
                'parameter_key' => $parameterKey,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $executionContext
     */
    private function resolveWindowEnd(array $executionContext): CarbonImmutable
    {
        $recordedAtValue = data_get($executionContext, 'trigger.recorded_at');

        if (is_string($recordedAtValue) && trim($recordedAtValue) !== '') {
            try {
                return CarbonImmutable::parse($recordedAtValue);
            } catch (\Throwable) {
                // Fall back to now when the timestamp cannot be parsed.
            }
        }

        return CarbonImmutable::now();
    }

    private function resolveWindowStart(CarbonImmutable $windowEnd, int $size, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'minute' => $windowEnd->subMinutes($size),
            'hour' => $windowEnd->subHours($size),
            'day' => $windowEnd->subDays($size),
            default => $windowEnd,
        };
    }

    /**
     * @param  array<int, array{alias: string, device_id: int, device_channel_id: int, parameter_key: string}>  $sources
     * @return array<int, array{
     *     alias: string,
     *     device_id: int,
     *     device_channel_id: int,
     *     channel_key: string,
     *     parameter_key: string,
     *     parameter_path: string
     * }>
     */
    private function resolveSourceDefinitions(int $organizationId, array $sources): array
    {
        $deviceIds = array_values(array_unique(array_map(static fn (array $source): int => $source['device_id'], $sources)));
        $channelIds = array_values(array_unique(array_map(static fn (array $source): int => $source['device_channel_id'], $sources)));
        $parameterKeys = array_values(array_unique(array_map(static fn (array $source): string => $source['parameter_key'], $sources)));

        $devices = Device::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $deviceIds)
            ->get(['id', 'device_profile_version_id'])
            ->keyBy('id');

        $channels = DeviceChannel::query()
            ->whereIn('id', $channelIds)
            ->get(['id', 'device_profile_version_id', 'key', 'direction'])
            ->keyBy('id');

        $parameters = ProfileParameterDefinition::query()
            ->whereIn('device_channel_id', $channelIds)
            ->whereIn('key', $parameterKeys)
            ->where('is_active', true)
            ->get(['id', 'device_channel_id', 'key', 'json_path'])
            ->keyBy(static fn (ProfileParameterDefinition $parameter): string => $parameter->device_channel_id.':'.$parameter->key);

        $resolved = [];

        foreach ($sources as $source) {
            $device = $devices->get($source['device_id']);
            $channel = $channels->get($source['device_channel_id']);
            $parameter = $parameters->get($source['device_channel_id'].':'.$source['parameter_key']);

            if (! $device instanceof Device) {
                throw new RuntimeException("Query node source [{$source['alias']}] references invalid device.");
            }

            if (! $channel instanceof DeviceChannel || ! $channel->isPublish()) {
                throw new RuntimeException("Query node source [{$source['alias']}] references invalid publish channel.");
            }

            if (! $parameter instanceof ProfileParameterDefinition) {
                throw new RuntimeException("Query node source [{$source['alias']}] references invalid parameter.");
            }

            if ((int) $channel->device_profile_version_id !== (int) $device->device_profile_version_id) {
                throw new RuntimeException("Query node source [{$source['alias']}] channel does not belong to selected device profile.");
            }

            if ((int) $parameter->device_channel_id !== (int) $channel->id) {
                throw new RuntimeException("Query node source [{$source['alias']}] parameter does not belong to selected channel.");
            }

            $resolved[] = [
                'alias' => $source['alias'],
                'device_id' => $source['device_id'],
                'device_channel_id' => $source['device_channel_id'],
                'channel_key' => $channel->key,
                'parameter_key' => $parameter->key,
                'parameter_path' => $parameter->json_path,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<int, array{
     *     alias: string,
     *     device_id: int,
     *     device_channel_id: int,
     *     channel_key: string,
     *     parameter_key: string,
     *     parameter_path: string
     * }>  $sourceDefinitions
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildSourceCteSql(array $sourceDefinitions, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $cteParts = [];
        $bindings = [];

        foreach ($sourceDefinitions as $source) {
            $alias = $source['alias'];
            $pathLiteral = $this->resolvePostgresPathLiteral($source['parameter_path']);
            $parameterKey = $this->resolvePostgresTextLiteral($source['parameter_key']);

            $rawValueExpression = "COALESCE(transformed_values ->> '{$parameterKey}', transformed_values #>> '{$pathLiteral}', raw_payload #>> '{$pathLiteral}')";

            $deviceKey = "device_{$alias}";
            $channelKey = "channel_{$alias}";
            $windowStartKey = "window_start_{$alias}";
            $windowEndKey = "window_end_{$alias}";

            $bindings[$deviceKey] = $source['device_id'];
            $bindings[$channelKey] = $source['device_channel_id'];
            $bindings[$windowStartKey] = $windowStart->toDateTimeString();
            $bindings[$windowEndKey] = $windowEnd->toDateTimeString();

            $cteParts[] = <<<SQL
{$alias} AS (
    SELECT
        recorded_at,
        device_id,
        device_channel_id,
        '{$this->resolvePostgresTextLiteral($source['channel_key'])}'::text AS channel_key,
        '{$parameterKey}'::text AS parameter_key,
        {$rawValueExpression} AS raw_value,
        CASE
            WHEN {$rawValueExpression} ~ '^[-+]?[0-9]*\\.?[0-9]+([eE][-+]?[0-9]+)?$'
                THEN ({$rawValueExpression}::double precision)
            ELSE NULL
        END AS value
    FROM device_telemetry_logs
    WHERE device_id = :{$deviceKey}
        AND device_channel_id = :{$channelKey}
        AND recorded_at >= :{$windowStartKey}
        AND recorded_at <= :{$windowEndKey}
)
SQL;
        }

        $cteSql = "WITH\n".implode(",\n", $cteParts);

        return [$cteSql, $bindings];
    }

    private function resolvePostgresPathLiteral(string $jsonPath): string
    {
        $normalizedPath = trim($jsonPath);

        if ($normalizedPath === '$') {
            throw new RuntimeException('Query node source parameter json path is invalid.');
        }

        if (str_starts_with($normalizedPath, '$.')) {
            $normalizedPath = substr($normalizedPath, 2);
        }

        $segments = array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), explode('.', $normalizedPath)),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            throw new RuntimeException('Query node source parameter json path is invalid.');
        }

        foreach ($segments as $segment) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $segment)) {
                throw new RuntimeException("Query node source json path segment [{$segment}] is not supported.");
            }
        }

        return '{'.implode(',', $segments).'}';
    }

    private function resolvePostgresTextLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function resolvePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }

    private function resolveNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $resolved = trim($value);

        return $resolved === '' ? null : $resolved;
    }

    private function resolveNumericValue(mixed $value): int|float
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            $resolvedValue = (float) $value;

            if ((float) ((int) $resolvedValue) === $resolvedValue) {
                return (int) $resolvedValue;
            }

            return $resolvedValue;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        throw new RuntimeException('Query node result [value] is not numeric.');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $resolved = [];

        foreach ($row as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $resolved[$key] = $value->format(DATE_ATOM);

                continue;
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }
}
