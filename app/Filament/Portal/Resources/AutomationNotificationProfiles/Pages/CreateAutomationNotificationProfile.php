<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationNotificationProfiles\Pages;

use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Services\ThresholdPolicyWorkflowProjector;
use App\Filament\Portal\Resources\AutomationNotificationProfiles\AutomationNotificationProfileResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateAutomationNotificationProfile extends CreateRecord
{
    protected static string $resource = AutomationNotificationProfileResource::class;

    /** @var array<int, int> */
    private array $recipientUserIds = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->recipientUserIds = AutomationNotificationProfileResource::validateRecipientUserIds(
            is_array($data['recipient_user_ids'] ?? null) ? $data['recipient_user_ids'] : [],
            is_string($data['channel'] ?? null) ? $data['channel'] : 'email',
        );

        unset($data['recipient_user_ids']);
        $data['organization_id'] = Filament::getTenant()?->getKey();
        $data['recipients'] = [];

        return $data;
    }

    protected function afterCreate(): void
    {
        $profile = $this->getRecord();

        if ($profile instanceof AutomationNotificationProfile) {
            $profile->users()->sync($this->recipientUserIds);
            app(ThresholdPolicyWorkflowProjector::class)->syncForNotificationProfile($profile);
        }
    }
}
