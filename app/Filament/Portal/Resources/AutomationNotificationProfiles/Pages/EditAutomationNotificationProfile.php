<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationNotificationProfiles\Pages;

use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Services\ThresholdPolicyWorkflowProjector;
use App\Filament\Portal\Resources\AutomationNotificationProfiles\AutomationNotificationProfileResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditAutomationNotificationProfile extends EditRecord
{
    protected static string $resource = AutomationNotificationProfileResource::class;

    /** @var array<int, int> */
    private array $recipientUserIds = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()->label('Archive'),
            Actions\RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->recipientUserIds = AutomationNotificationProfileResource::validateRecipientUserIds(
            is_array($data['recipient_user_ids'] ?? null) ? $data['recipient_user_ids'] : [],
            is_string($data['channel'] ?? null) ? $data['channel'] : 'email',
        );

        unset($data['recipient_user_ids']);
        $data['organization_id'] = Filament::getTenant()?->getKey();

        return $data;
    }

    protected function afterSave(): void
    {
        $profile = $this->getRecord();

        if ($profile instanceof AutomationNotificationProfile) {
            $profile->users()->sync($this->recipientUserIds);
            app(ThresholdPolicyWorkflowProjector::class)->syncForNotificationProfile($profile);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $profile = $this->getRecord();
        $data['recipient_user_ids'] = $profile instanceof AutomationNotificationProfile
            ? $profile->users()->pluck('users.id')->all()
            : [];

        return $data;
    }
}
