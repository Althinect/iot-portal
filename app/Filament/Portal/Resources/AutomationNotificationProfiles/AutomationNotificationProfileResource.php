<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationNotificationProfiles;

use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Shared\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\ValidationException;

class AutomationNotificationProfileResource extends Resource
{
    protected static ?string $model = AutomationNotificationProfile::class;

    protected static ?string $slug = 'notification-profiles';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 7;

    public static function getNavigationGroup(): ?string
    {
        return 'Alerts & Automation';
    }

    public static function getNavigationLabel(): string
    {
        return 'Notification Profiles';
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\AutomationNotificationProfileForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\AutomationNotificationProfileInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\AutomationNotificationProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutomationNotificationProfiles::route('/'),
            'create' => Pages\CreateAutomationNotificationProfile::route('/create'),
            'view' => Pages\ViewAutomationNotificationProfile::route('/{record}'),
            'edit' => Pages\EditAutomationNotificationProfile::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * @param  array<int, mixed>  $recipientUserIds
     * @return array<int, int>
     */
    public static function validateRecipientUserIds(array $recipientUserIds, string $channel): array
    {
        $tenantId = Filament::getTenant()?->getKey();
        abort_unless(is_numeric($tenantId), 404);

        $normalizedIds = collect($recipientUserIds)
            ->filter(fn (mixed $userId): bool => is_numeric($userId))
            ->map(fn (mixed $userId): int => (int) $userId)
            ->unique()
            ->values();

        if ($normalizedIds->isEmpty()) {
            throw ValidationException::withMessages([
                'recipient_user_ids' => 'Select at least one contactable organization member.',
            ]);
        }

        $validIds = User::query()
            ->whereKey($normalizedIds->all())
            ->whereHas(
                'organizations',
                fn (Builder $query): Builder => $query->whereKey((int) $tenantId),
            )
            ->when(
                $channel === 'sms',
                fn (Builder $query): Builder => $query->whereNotNull('phone_number'),
                fn (Builder $query): Builder => $query->whereNotNull('email'),
            )
            ->pluck('id')
            ->map(fn (mixed $userId): int => (int) $userId)
            ->sort()
            ->values();

        if ($normalizedIds->sort()->values()->all() !== $validIds->all()) {
            throw ValidationException::withMessages([
                'recipient_user_ids' => 'Recipients must be contactable members of the active organization.',
            ]);
        }

        return $validIds->all();
    }
}
