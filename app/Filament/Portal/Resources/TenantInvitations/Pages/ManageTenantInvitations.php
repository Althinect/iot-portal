<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\TenantInvitations\Pages;

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\TenantInvitationService;
use App\Filament\Portal\Resources\TenantInvitations\TenantInvitationResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ManageTenantInvitations extends ManageRecords
{
    protected static string $resource = TenantInvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inviteMember')
                ->label('Invite member')
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => TenantInvitationResource::canCreate())
                ->schema([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Select::make('tenant_role_key')
                        ->label('Role')
                        ->options(collect(TenantRole::cases())
                            ->mapWithKeys(fn (TenantRole $role): array => [$role->value => $role->label()]))
                        ->default(TenantRole::Viewer->value)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $organization = Filament::getTenant();
                    $user = Auth::user();
                    abort_unless($organization instanceof Organization && $user instanceof User, 403);

                    app(TenantInvitationService::class)->invite(
                        $organization,
                        $data['email'],
                        TenantRole::from($data['tenant_role_key']),
                        $user,
                    );

                    Notification::make()
                        ->success()
                        ->title('Invitation sent')
                        ->send();
                }),
        ];
    }
}
