<?php

namespace App\Filament\Admin\Resources\Shared\Users;

use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\Shared\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Shared\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Shared\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Shared\Users\Pages\ViewUser;
use App\Filament\Admin\Resources\Shared\Users\RelationManagers\OrganizationRelationManager;
use App\Filament\Admin\Resources\Shared\Users\RelationManagers\RoleRelationManager;
use App\Filament\Admin\Resources\Shared\Users\Shemas\UserForm;
use App\Filament\Admin\Resources\Shared\Users\Shemas\UserInfolist;
use App\Filament\Admin\Resources\Shared\Users\Tables\UserTable;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::Users;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UserTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OrganizationRelationManager::class,
            RoleRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
