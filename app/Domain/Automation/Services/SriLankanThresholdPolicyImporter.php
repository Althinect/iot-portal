<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use Database\Seeders\SriLankanMigrationSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class SriLankanThresholdPolicyImporter
{
    public function __construct(
        private readonly ThresholdPolicyWorkflowProjector $thresholdPolicyWorkflowProjector,
    ) {}

    /**
     * @return array{
     *     profiles_created: int,
     *     profiles_updated: int,
     *     policies_created: int,
     *     policies_updated: int,
     *     workflows_synced: int,
     *     skipped: array<int, string>
     * }
     */
    public function import(string $organizationSlug = SriLankanMigrationSeeder::ORGANIZATION_SLUG): array
    {
        $organization = Organization::query()
            ->where('slug', $organizationSlug)
            ->first();

        if (! $organization instanceof Organization) {
            throw new RuntimeException("Organization [{$organizationSlug}] could not be found.");
        }

        $devices = Device::query()
            ->where('organization_id', $organization->id)
            ->with(['profileVersion.channels.parameters'])
            ->get();

        $deviceMappings = $this->buildLegacyRuleDeviceMap($devices);
        $legacyRuleIds = array_keys($deviceMappings);

        if ($legacyRuleIds === []) {
            return [
                'profiles_created' => 0,
                'profiles_updated' => 0,
                'policies_created' => 0,
                'policies_updated' => 0,
                'workflows_synced' => 0,
                'skipped' => [],
            ];
        }

        $legacyRules = $this->fetchLegacyRules($legacyRuleIds);
        $templateIds = $legacyRules->pluck('alert_template_id')
            ->map(fn (mixed $value): ?int => $this->resolveLegacyIntegerIdentifier($value))
            ->filter(static fn (?int $value): bool => $value !== null)
            ->unique()
            ->values()
            ->all();
        $legacyTemplates = $this->fetchLegacyTemplates($templateIds);

        $summary = [
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'policies_created' => 0,
            'policies_updated' => 0,
            'workflows_synced' => 0,
            'skipped' => [],
        ];
        $profilesByTemplateId = [];

        foreach ($deviceMappings as $legacyRuleId => $mapping) {
            $legacyRule = $legacyRules->get($legacyRuleId);

            if (! $legacyRule instanceof stdClass) {
                $summary['skipped'][] = "Legacy alert rule [{$legacyRuleId}] was not found.";

                continue;
            }

            $legacyTemplate = $legacyTemplates->get((int) $legacyRule->alert_template_id);

            if (! $legacyTemplate instanceof stdClass) {
                $summary['skipped'][] = "Legacy template [{$legacyRule->alert_template_id}] was not found for rule [{$legacyRuleId}].";

                continue;
            }

            $templateId = (int) $legacyTemplate->id;
            $profile = $profilesByTemplateId[$templateId] ??= $this->upsertNotificationProfile($organization, $legacyTemplate, $summary);
            $policy = $this->upsertThresholdPolicy(
                organization: $organization,
                device: $mapping['device'],
                parameter: $mapping['parameter'],
                legacyRule: $legacyRule,
                profile: $profile,
                sortOrder: $mapping['sort_order'],
                summary: $summary,
            );

            if ($this->thresholdPolicyWorkflowProjector->sync($policy) !== null) {
                $summary['workflows_synced']++;
            }
        }

        return $summary;
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<string, array{
     *     device: Device,
     *     parameter: ProfileParameterDefinition,
     *     sort_order: int
     * }>
     */
    private function buildLegacyRuleDeviceMap(Collection $devices): array
    {
        $mapping = [];

        foreach ($devices as $device) {
            $metadata = $device->getAttributeValue('metadata');

            if (! is_array($metadata)) {
                continue;
            }

            $legacyRuleIds = $metadata['legacy_alert_rule_ids'] ?? null;

            if (! is_array($legacyRuleIds) || $legacyRuleIds === []) {
                continue;
            }

            $parameter = $this->resolveTemperatureParameter($device);

            if (! $parameter instanceof ProfileParameterDefinition) {
                continue;
            }

            foreach (array_values($legacyRuleIds) as $sortOrder => $legacyRuleId) {
                if (! is_string($legacyRuleId) || trim($legacyRuleId) === '') {
                    continue;
                }

                $mapping[trim($legacyRuleId)] = [
                    'device' => $device,
                    'parameter' => $parameter,
                    'sort_order' => $sortOrder,
                ];
            }
        }

        return $mapping;
    }

    private function resolveTemperatureParameter(Device $device): ?ProfileParameterDefinition
    {
        $device->loadMissing('profileVersion.channels.parameters');

        return $device->profileVersion?->channels
            ?->first(
                fn (DeviceChannel $channel): bool => $channel->isPublish()
                    && $channel->parameters->contains(
                        fn (ProfileParameterDefinition $parameter): bool => $parameter->key === 'temperature' && $parameter->is_active
                    )
            )
            ?->parameters
            ->first(
                fn (ProfileParameterDefinition $parameter): bool => $parameter->key === 'temperature' && $parameter->is_active
            );
    }

    /**
     * @param  array<int, string>  $legacyRuleIds
     * @return Collection<string, stdClass>
     */
    private function fetchLegacyRules(array $legacyRuleIds): Collection
    {
        return DB::connection('legacy_iot')
            ->table('alert_rules')
            ->select([
                'id',
                'mongodb_id',
                'name',
                'enabled',
                'alert_interval',
                'logic',
                'alert_template_id',
                'organization_id',
                'attributes',
            ])
            ->whereIn('mongodb_id', $legacyRuleIds)
            ->get()
            ->keyBy(static fn (stdClass $rule): string => (string) $rule->mongodb_id);
    }

    /**
     * @param  array<int, int>  $templateIds
     * @return Collection<int, stdClass>
     */
    private function fetchLegacyTemplates(array $templateIds): Collection
    {
        return DB::connection('legacy_iot')
            ->table('alert_templates as templates')
            ->leftJoin('alert_providers as providers', 'providers.id', '=', 'templates.alert_provider_id')
            ->select([
                'templates.id',
                'templates.mongodb_id',
                'templates.name',
                'templates.attributes',
                'providers.mongodb_id as provider_mongodb_id',
                'providers.name as provider_name',
                'providers.type as provider_type',
                'providers.driver as provider_driver',
                'providers.configuration as provider_configuration',
            ])
            ->whereIn('templates.id', $templateIds)
            ->get()
            ->keyBy(static fn (stdClass $template): int => (int) $template->id);
    }

    /**
     * @param  array{
     *     profiles_created: int,
     *     profiles_updated: int,
     *     policies_created: int,
     *     policies_updated: int,
     *     workflows_synced: int,
     *     skipped: array<int, string>
     * }  $summary
     */
    private function upsertNotificationProfile(
        Organization $organization,
        stdClass $legacyTemplate,
        array &$summary,
    ): AutomationNotificationProfile {
        $templateAttributes = $this->decodeJson($legacyTemplate->attributes);
        $providerConfiguration = $this->decodeJson($legacyTemplate->provider_configuration);
        $configuration = $templateAttributes['configuration'] ?? null;
        $templateConfiguration = is_array($configuration)
            ? $this->normalizeStringKeyArray($configuration)
            : [];
        $recipients = $templateConfiguration['recipients'] ?? [];
        $channel = $this->resolveProfileChannel($legacyTemplate);
        $normalizedRecipients = $this->normalizeRecipients(is_array($recipients) ? $recipients : [], $channel);
        $profileName = 'Legacy '.$channel.' · '.trim((string) $legacyTemplate->name);

        $profile = AutomationNotificationProfile::query()
            ->withTrashed()
            ->firstOrNew([
                'organization_id' => $organization->id,
                'name' => $profileName,
            ]);

        $wasRecentlyCreated = ! $profile->exists;

        $profile->fill([
            'channel' => $channel,
            'enabled' => true,
            'recipients' => $normalizedRecipients,
            'subject' => $channel === 'email'
                ? 'Temperature threshold breach · {{ trigger.device_name }}'
                : 'Temperature threshold breach · {{ trigger.device_name }}',
            'body' => $this->translateLegacyTemplateBody($this->resolveStringValue($templateConfiguration['body'] ?? null) ?? ''),
            'mask' => $this->resolveStringValue($templateConfiguration['mask'] ?? null),
            'campaign_name' => $this->resolveStringValue($templateConfiguration['campaignName'] ?? null),
            'legacy_metadata' => [
                'template_mongodb_id' => $legacyTemplate->mongodb_id,
                'provider_mongodb_id' => $legacyTemplate->provider_mongodb_id,
                'provider_name' => $legacyTemplate->provider_name,
                'provider_type' => $legacyTemplate->provider_type,
                'provider_driver' => $legacyTemplate->provider_driver,
                'provider_configuration' => $providerConfiguration,
                'template_attributes' => $templateAttributes,
            ],
            'deleted_at' => null,
        ]);
        $profile->save();
        $this->syncProfileUsers($organization, $profile, $normalizedRecipients);

        if ($wasRecentlyCreated) {
            $summary['profiles_created']++;
        } else {
            $summary['profiles_updated']++;
        }

        return $profile;
    }

    /**
     * @param  array{
     *     profiles_created: int,
     *     profiles_updated: int,
     *     policies_created: int,
     *     policies_updated: int,
     *     workflows_synced: int,
     *     skipped: array<int, string>
     * }  $summary
     */
    private function upsertThresholdPolicy(
        Organization $organization,
        Device $device,
        ProfileParameterDefinition $parameter,
        stdClass $legacyRule,
        AutomationNotificationProfile $profile,
        int $sortOrder,
        array &$summary,
    ): AutomationThresholdPolicy {
        ['minimum_value' => $minimumValue, 'maximum_value' => $maximumValue] = $this->parseLegacyLogic($legacyRule->logic);
        ['value' => $cooldownValue, 'unit' => $cooldownUnit] = $this->mapCooldown((int) $legacyRule->alert_interval);

        $policy = AutomationThresholdPolicy::query()
            ->withTrashed()
            ->firstOrNew([
                'organization_id' => $organization->id,
                'legacy_alert_rule_id' => (string) $legacyRule->mongodb_id,
            ]);

        $wasRecentlyCreated = ! $policy->exists;

        $policy->fill([
            'organization_id' => $organization->id,
            'device_id' => $device->id,
            'device_channel_id' => $parameter->device_channel_id,
            'parameter_key' => $parameter->key,
            'name' => trim((string) $legacyRule->name) !== ''
                ? trim((string) $legacyRule->name)
                : $device->name.' Temperature Threshold',
            'minimum_value' => $minimumValue,
            'maximum_value' => $maximumValue,
            'is_active' => (bool) $legacyRule->enabled,
            'cooldown_value' => $cooldownValue,
            'cooldown_unit' => $cooldownUnit,
            'notification_profile_id' => $profile->id,
            'sort_order' => $sortOrder,
            'legacy_metadata' => [
                'legacy_rule_id' => $legacyRule->id,
                'legacy_rule_mongodb_id' => $legacyRule->mongodb_id,
                'legacy_organization_id' => $legacyRule->organization_id,
                'legacy_logic' => $this->decodeJson($legacyRule->logic),
                'legacy_attributes' => $this->decodeJson($legacyRule->attributes),
            ],
            'deleted_at' => null,
        ]);
        $policy->save();

        if ($wasRecentlyCreated) {
            $summary['policies_created']++;
        } else {
            $summary['policies_updated']++;
        }

        return $policy;
    }

    /**
     * @return array{minimum_value: float|null, maximum_value: float|null}
     */
    private function parseLegacyLogic(mixed $logic): array
    {
        $decodedLogic = $this->decodeJson($logic);
        $comparisons = $decodedLogic['or'] ?? [$decodedLogic];
        $minimumValue = null;
        $maximumValue = null;

        if (! is_array($comparisons)) {
            throw new RuntimeException('Legacy alert rule logic must be an array.');
        }

        foreach ($comparisons as $comparison) {
            if (! is_array($comparison) || count($comparison) !== 1) {
                continue;
            }

            $operator = array_key_first($comparison);
            $operands = $comparison[$operator] ?? null;

            if (! is_string($operator) || ! is_array($operands) || count($operands) < 2) {
                continue;
            }

            $thresholdValue = $this->resolveNumericValue($operands[1] ?? null);

            if ($thresholdValue === null) {
                continue;
            }

            if (in_array($operator, ['<', '<='], true)) {
                $minimumValue = $thresholdValue;
            }

            if (in_array($operator, ['>', '>='], true)) {
                $maximumValue = $thresholdValue;
            }
        }

        return [
            'minimum_value' => $minimumValue,
            'maximum_value' => $maximumValue,
        ];
    }

    /**
     * @return array{value: int, unit: string}
     */
    private function mapCooldown(int $seconds): array
    {
        if ($seconds > 0 && $seconds % 86400 === 0) {
            return [
                'value' => max(1, (int) ($seconds / 86400)),
                'unit' => 'day',
            ];
        }

        if ($seconds > 0 && $seconds % 3600 === 0) {
            return [
                'value' => max(1, (int) ($seconds / 3600)),
                'unit' => 'hour',
            ];
        }

        return [
            'value' => max(1, (int) ceil(max(60, $seconds) / 60)),
            'unit' => 'minute',
        ];
    }

    private function resolveProfileChannel(stdClass $legacyTemplate): string
    {
        $providerType = strtolower(trim((string) ($legacyTemplate->provider_type ?? '')));
        $providerDriver = strtolower(trim((string) ($legacyTemplate->provider_driver ?? '')));

        if ($providerType === 'sms' || $providerDriver === 'dialog') {
            return 'sms';
        }

        return 'email';
    }

    /**
     * @param  array<int, mixed>  $recipients
     * @return array<int, string>
     */
    private function normalizeRecipients(array $recipients, string $channel): array
    {
        $normalized = [];

        foreach ($recipients as $recipient) {
            if (! is_string($recipient)) {
                continue;
            }

            $resolvedRecipient = trim($recipient);

            if ($resolvedRecipient === '') {
                continue;
            }

            if ($channel === 'sms') {
                $normalizedPhone = $this->normalizePhoneRecipient($resolvedRecipient);

                if ($normalizedPhone === null) {
                    continue;
                }

                $normalized[$normalizedPhone] = $normalizedPhone;

                continue;
            }

            $normalizedEmail = strtolower($resolvedRecipient);

            if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $normalized[$normalizedEmail] = $normalizedEmail;
        }

        return array_values($normalized);
    }

    /**
     * @param  array<int, string>  $normalizedRecipients
     */
    private function syncProfileUsers(
        Organization $organization,
        AutomationNotificationProfile $profile,
        array $normalizedRecipients,
    ): void {
        if ($normalizedRecipients === []) {
            $profile->users()->sync([]);

            return;
        }

        $organizationUsers = $organization->users()
            ->get(['users.id', 'users.email', 'users.phone_number']);
        $userIds = [];
        $unmappedRecipients = [];

        foreach ($normalizedRecipients as $recipient) {
            $user = $organizationUsers->first(function ($user) use ($profile, $recipient): bool {
                if ($profile->channel === 'sms') {
                    return $this->normalizePhoneRecipient((string) $user->phone_number) === $recipient;
                }

                return strtolower((string) $user->email) === strtolower($recipient);
            });

            if ($user === null) {
                $unmappedRecipients[] = $recipient;

                continue;
            }

            $userIds[] = (int) $user->id;
        }

        if ($unmappedRecipients !== []) {
            throw new RuntimeException(sprintf(
                'Legacy notification profile [%s] has recipients that do not map to organization users: %s',
                $profile->name,
                implode(', ', $unmappedRecipients),
            ));
        }

        $profile->users()->sync(array_values(array_unique($userIds)));
    }

    private function translateLegacyTemplateBody(string $body): string
    {
        $resolvedBody = trim($body);

        if ($resolvedBody === '') {
            return '{{ trigger.device_name }}: current temperature = {{ trigger.value }}°C condition {{ alert.metadata.condition_label }}';
        }

        $translatedBody = str_replace(
            ['{{.deviceName}}', '{{.temperature}}', '{{.condition}}'],
            ['{{ trigger.device_name }}', '{{ trigger.value }}', '{{ alert.metadata.condition_label }}'],
            $resolvedBody,
        );

        return str_replace('{{ trigger.value }}C', '{{ trigger.value }}°C', $translatedBody);
    }

    private function normalizePhoneRecipient(string $value): ?string
    {
        $normalized = preg_replace('/\s+/', '', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        if (preg_match('/^\+[1-9][0-9]{7,14}$/', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('/^[1-9][0-9]{7,14}$/', $normalized) === 1) {
            return '+'.$normalized;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $this->normalizeStringKeyArray($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $this->normalizeStringKeyArray($decoded) : [];
    }

    private function resolveNumericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value) || trim($value) === '' || ! is_numeric(trim($value))) {
            return null;
        }

        return (float) trim($value);
    }

    private function resolveStringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $resolvedValue = trim($value);

        return $resolvedValue === '' ? null : $resolvedValue;
    }

    private function resolveLegacyIntegerIdentifier(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizeStringKeyArray(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[(string) $key] = is_array($value)
                ? $this->normalizeStringKeyArray($value)
                : $value;
        }

        return $normalized;
    }
}
