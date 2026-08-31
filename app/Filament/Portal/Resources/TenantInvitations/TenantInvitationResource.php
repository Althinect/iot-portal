<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\TenantInvitations;

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Shared\Models\TenantInvitation;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\TenantInvitationService;
use App\Filament\Portal\Resources\TenantInvitations\Pages\ManageTenantInvitations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class TenantInvitationResource extends Resource
{
    protected static ?string $model = TenantInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Organization';

    protected static ?string $navigationLabel = 'Invitations';

    protected static ?int $navigationSort = 11;

    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tenant_role_key')
                    ->label('Role')
                    ->formatStateUsing(fn (TenantRole $state): string => $state->label())
                    ->badge(),
                TextColumn::make('status')
                    ->state(fn (TenantInvitation $record): string => match (true) {
                        $record->accepted_at !== null => 'Accepted',
                        $record->revoked_at !== null => 'Revoked',
                        ! $record->isPending() => 'Expired',
                        default => 'Pending',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Accepted' => 'success',
                        'Pending' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('invitedBy.name')
                    ->label('Invited by')
                    ->placeholder('System'),
                TextColumn::make('last_sent_at')
                    ->label('Last sent')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'accepted' => 'Accepted',
                        'revoked' => 'Revoked',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'pending' => $query
                                ->whereNull('accepted_at')
                                ->whereNull('revoked_at')
                                ->where('expires_at', '>', now()),
                            'accepted' => $query->whereNotNull('accepted_at'),
                            'revoked' => $query->whereNotNull('revoked_at'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('copyLink')
                    ->label('Copy link')
                    ->icon(Heroicon::OutlinedClipboard)
                    ->visible(fn (TenantInvitation $record): bool => $record->isPending())
                    ->schema([
                        TextInput::make('url')
                            ->label('Invitation link')
                            ->readOnly()
                            ->extraInputAttributes(['x-on:focus' => '$el.select()']),
                    ])
                    ->fillForm(function (TenantInvitation $record): array {
                        $user = Auth::user();
                        abort_unless($user instanceof User, 403);

                        return [
                            'url' => app(TenantInvitationService::class)
                                ->resend($record, $user, false)
                                ->url,
                        ];
                    })
                    ->modalDescription('This generates a new link and invalidates the previous one.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('resend')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->visible(fn (TenantInvitation $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->action(function (TenantInvitation $record): void {
                        $user = Auth::user();
                        abort_unless($user instanceof User, 403);
                        app(TenantInvitationService::class)->resend($record, $user);

                        Notification::make()->success()->title('Invitation resent')->send();
                    }),
                Action::make('revoke')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXMark)
                    ->visible(fn (TenantInvitation $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->action(function (TenantInvitation $record): void {
                        $user = Auth::user();
                        abort_unless($user instanceof User, 403);
                        app(TenantInvitationService::class)->revoke($record, $user);

                        Notification::make()->success()->title('Invitation revoked')->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        return parent::getEloquentQuery()
            ->when(
                $tenant !== null,
                fn (Builder $query): Builder => $query->where('organization_id', $tenant->getKey()),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            );
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTenantInvitations::route('/'),
        ];
    }
}
