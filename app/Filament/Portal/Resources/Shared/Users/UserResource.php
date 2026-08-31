<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Shared\Users;

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\OrganizationMemberPermission;
use App\Domain\Shared\Services\TenantMemberManager;
use App\Filament\Portal\Resources\Shared\Users\Pages\ListUsers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Organization';

    protected static ?string $navigationLabel = 'Members';

    protected static ?string $modelLabel = 'member';

    protected static ?string $pluralModelLabel = 'members';

    protected static ?int $navigationSort = 10;

    protected static ?string $tenantOwnershipRelationshipName = 'organizations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tenant_role')
                    ->label('Role')
                    ->state(function (User $record): string {
                        $organization = self::tenant();
                        $role = $organization instanceof Organization
                            ? app(TenantMemberManager::class)->roleFor($record, $organization)
                            : null;

                        return $role?->label() ?? 'Viewer';
                    })
                    ->badge(),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change role')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn (User $record): bool => self::canEdit($record))
                    ->schema([
                        Select::make('tenant_role_key')
                            ->label('Role')
                            ->options(collect(TenantRole::cases())
                                ->mapWithKeys(fn (TenantRole $role): array => [$role->value => $role->label()]))
                            ->required(),
                    ])
                    ->fillForm(function (User $record): array {
                        $organization = self::tenant();

                        return [
                            'tenant_role_key' => $organization instanceof Organization
                                ? app(TenantMemberManager::class)->roleFor($record, $organization)?->value
                                : null,
                        ];
                    })
                    ->action(function (User $record, array $data): void {
                        $organization = self::tenant();
                        $actor = Auth::user();
                        abort_unless($organization instanceof Organization && $actor instanceof User, 403);

                        app(TenantMemberManager::class)->changeRole(
                            $actor,
                            $record,
                            $organization,
                            TenantRole::from($data['tenant_role_key']),
                        );

                        Notification::make()->success()->title('Member role updated')->send();
                    }),
                Action::make('removeMember')
                    ->label('Remove')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->visible(fn (User $record): bool => self::canDelete($record))
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $organization = self::tenant();
                        $actor = Auth::user();
                        abort_unless($organization instanceof Organization && $actor instanceof User, 403);

                        app(TenantMemberManager::class)->detach($actor, $record, $organization);

                        Notification::make()->success()->title('Member removed')->send();
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = self::tenant();

        return parent::getEloquentQuery()
            ->where('is_super_admin', false)
            ->when(
                $tenant instanceof Organization,
                fn (Builder $query): Builder => $query->whereHas(
                    'organizations',
                    fn (Builder $query): Builder => $query->whereKey($tenant->id),
                ),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            );
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && app(TenantAuthorization::class)->allows(
                $user,
                OrganizationMemberPermission::VIEW_ANY,
            );
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof User && self::allowsForMember(
            $record,
            OrganizationMemberPermission::VIEW_ANY,
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof User && self::allowsForMember(
            $record,
            OrganizationMemberPermission::UPDATE_ROLE,
        );
    }

    public static function canDelete(Model $record): bool
    {
        $actor = Auth::user();

        return $record instanceof User
            && $actor instanceof User
            && ! $actor->is($record)
            && self::allowsForMember($record, OrganizationMemberPermission::DETACH);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
        ];
    }

    private static function allowsForMember(User $member, OrganizationMemberPermission $permission): bool
    {
        $organization = self::tenant();
        $actor = Auth::user();

        return $organization instanceof Organization
            && $actor instanceof User
            && $member->organizations()->whereKey($organization->id)->exists()
            && app(TenantAuthorization::class)->allows($actor, $permission, $organization->id);
    }

    private static function tenant(): ?Organization
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization ? $tenant : null;
    }
}
