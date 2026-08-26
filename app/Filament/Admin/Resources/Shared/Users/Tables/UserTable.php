<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Shared\Users\Tables;

use App\Domain\Shared\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use STS\FilamentImpersonate\Actions\Impersonate;

class UserTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query())
            ->columns([
                TextColumn::make('id')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone_number')
                    ->label('Phone')
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('organization_id')
                    ->label('Organization')
                    ->relationship('organizations', 'name')
                    ->searchable()
                    ->multiple()
                    ->preload(),
            ])
            ->recordActions([
                Impersonate::make()->authorize(function (User $record) {
                    /** @var User $authUser */
                    $authUser = Auth::user();

                    return $authUser->isSuperAdmin() && ! $record->isSuperAdmin();
                }),
                ViewAction::make(),
                EditAction::make(),
                Action::make('setCustomPassword')
                    ->label('Set custom password')
                    ->icon(Heroicon::Key)
                    ->color('danger')
                    ->modalHeading('Set custom password')
                    ->modalDescription('Use only when the user cannot receive a reset link. Copy the password before saving; it will not be shown again.')
                    ->modalSubmitActionLabel('Set password')
                    ->schema([
                        TextInput::make('password')
                            ->label('New password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rules([PasswordRule::defaults()])
                            ->helperText('Copy this password before saving.'),
                        TextInput::make('password_confirmation')
                            ->label('Confirm new password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->same('password'),
                    ])
                    ->action(function (array $data, User $record): void {
                        $record->forceFill([
                            'password' => $data['password'],
                        ])->setRememberToken(Str::random(60))->save();

                        Password::deleteToken($record);

                        Notification::make()
                            ->title('Password updated')
                            ->success()
                            ->send();
                    }),
                Action::make('sendPasswordResetLink')
                    ->label('Send password reset link')
                    ->icon(Heroicon::Key)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Send password reset link')
                    ->modalDescription(fn (User $record): string => "A secure, time-limited reset link will be sent to {$record->email}.")
                    ->action(function (User $record): void {
                        $status = Password::sendResetLink(['email' => $record->email]);

                        if ($status === Password::ResetLinkSent) {
                            Notification::make()
                                ->title('Password reset link sent')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Password reset link could not be sent')
                            ->danger()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
