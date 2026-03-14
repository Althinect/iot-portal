<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceManagement\Devices\Tables;

use App\Domain\DeviceManagement\Models\Device;
use App\Filament\Actions\DeviceManagement\ProvisionX509CertificateAction;
use App\Filament\Actions\DeviceManagement\ReplicateDeviceActions;
use App\Filament\Actions\DeviceManagement\RevokeX509CertificateAction;
use App\Filament\Actions\DeviceManagement\RotateX509CertificateAction;
use App\Filament\Actions\DeviceManagement\SimulatePublishingActions;
use App\Filament\Actions\DeviceManagement\ViewFirmwareAction;
use App\Filament\Admin\Resources\DeviceManagement\Devices\DeviceResource;
use App\Filament\Admin\Resources\DeviceManagement\DeviceTypes\DeviceTypeResource;
use App\Filament\Admin\Resources\Shared\Organizations\OrganizationResource;
use Filament\Actions;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn ($record) => $record->uuid),

                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Device $record): ?string => $record->organization_id
                        ? OrganizationResource::getUrl('view', ['record' => $record->organization_id])
                        : null),

                TextColumn::make('deviceType.name')
                    ->label('Type')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Device $record): ?string => $record->device_type_id
                        ? DeviceTypeResource::getUrl('view', ['record' => $record->device_type_id])
                        : null),

                TextColumn::make('schemaVersion.version')
                    ->label('Schema')
                    ->formatStateUsing(fn (mixed $state): string => is_scalar($state) ? "v{$state}" : '—')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('effective_connection_state')
                    ->label('Status')
                    ->state(fn (Device $record): string => $record->effectiveConnectionState())
                    ->icon(fn (?string $state): Heroicon => match ($state) {
                        'online' => Heroicon::Wifi,
                        'offline' => Heroicon::SignalSlash,
                        default => Heroicon::QuestionMarkCircle,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (Device $record): string => $record->presenceStatusTooltip()),

                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->since()
                    ->sortable()
                    ->placeholder('Never')
                    ->toggleable(),

                TextColumn::make('external_id')
                    ->label('External ID')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('temporaryDevice.expires_at')
                    ->label('Temporary Expires')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('organization')
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('deviceType')
                    ->relationship('deviceType', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('effective_connection_state')
                    ->label('Status')
                    ->options([
                        'online' => 'Online',
                        'offline' => 'Offline',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || ! in_array($value, ['online', 'offline'], true)) {
                            return $query;
                        }

                        return $query->whereEffectiveConnectionState($value);
                    }),

                Filter::make('temporary_devices')
                    ->label('Temporary devices')
                    ->query(fn (Builder $query): Builder => $query->whereHas('temporaryDevice')),
            ])
            ->recordActions([
                Actions\ActionGroup::make([
                    Actions\ViewAction::make(),
                    ViewFirmwareAction::make(),
                    ProvisionX509CertificateAction::make(),
                    RotateX509CertificateAction::make(),
                    RevokeX509CertificateAction::make(),
                    Actions\Action::make('controlDashboard')
                        ->label('Control')
                        ->icon(Heroicon::OutlinedCommandLine)
                        ->url(fn (Device $record): string => DeviceResource::getUrl('control-dashboard', ['record' => $record]))
                        ->visible(fn (Device $record): bool => $record->canBeControlled()),
                    SimulatePublishingActions::recordAction()
                        ->visible(fn (Device $record): bool => $record->canBeSimulated()),
                    Actions\EditAction::make(),
                    ReplicateDeviceActions::make(),
                    Actions\DeleteAction::make(),
                ])
                    ->label('Actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    SimulatePublishingActions::bulkAction(),
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }
}
