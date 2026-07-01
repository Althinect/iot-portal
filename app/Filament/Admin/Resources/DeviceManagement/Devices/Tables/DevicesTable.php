<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceManagement\Devices\Tables;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Filament\Actions\DeviceManagement\ProvisionX509CertificateAction;
use App\Filament\Actions\DeviceManagement\ReplicateDeviceActions;
use App\Filament\Actions\DeviceManagement\RevokeX509CertificateAction;
use App\Filament\Actions\DeviceManagement\RotateX509CertificateAction;
use App\Filament\Actions\DeviceManagement\SimulatePublishingActions;
use App\Filament\Actions\DeviceManagement\ViewFirmwareAction;
use App\Filament\Admin\Resources\DeviceManagement\Devices\DeviceResource;
use App\Filament\Admin\Resources\Shared\Organizations\OrganizationResource;
use Filament\Actions;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
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

                TextColumn::make('profileVersion.profile.name')
                    ->label('Profile')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('device_kind')
                    ->label('Kind')
                    ->state(fn (Device $record): string => $record->isVirtual() ? 'Virtual' : 'Physical')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Virtual' ? 'warning' : 'gray')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('is_virtual', $direction);
                    }),

                TextColumn::make('profileVersion.version')
                    ->label('Profile Version')
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

                TextColumn::make('parentDevice.name')
                    ->label('Parent Hub')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('child_devices_count')
                    ->label('Children')
                    ->counts('childDevices')
                    ->toggleable(),

                TextColumn::make('virtual_device_links_count')
                    ->label('Sources')
                    ->counts('virtualDeviceLinks')
                    ->toggleable(),

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

                SelectFilter::make('device_profile_id')
                    ->label('Device Profile')
                    ->options(fn (): array => self::profileOptions())
                    ->searchable()
                    ->preload()
                    ->query(function (Builder $query, array $data): Builder {
                        $deviceProfileId = $data['value'] ?? null;

                        if (! is_numeric($deviceProfileId)) {
                            return $query;
                        }

                        return $query->whereHas('profileVersion', function (Builder $profileVersionQuery) use ($deviceProfileId): void {
                            $profileVersionQuery->where('device_profile_id', (int) $deviceProfileId);
                        });
                    }),

                SelectFilter::make('device_profile_version_id')
                    ->label('Device Profile Version')
                    ->options(fn (mixed $livewire): array => self::profileVersionOptions($livewire))
                    ->searchable()
                    ->preload()
                    ->query(function (Builder $query, array $data): Builder {
                        $deviceProfileVersionId = $data['value'] ?? null;

                        if (! is_numeric($deviceProfileVersionId)) {
                            return $query;
                        }

                        return $query->where('device_profile_version_id', (int) $deviceProfileVersionId);
                    }),

                SelectFilter::make('is_virtual')
                    ->label('Kind')
                    ->options([
                        '0' => 'Physical',
                        '1' => 'Virtual',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            '0' => $query->where('is_virtual', false),
                            '1' => $query->where('is_virtual', true),
                            default => $query,
                        };
                    }),

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
            ->groups([
                Group::make('parent_device_id')
                    ->label('Parent Device')
                    ->getTitleFromRecordUsing(fn (Device $record): string => $record->parentDevice->name ?? 'No Parent Device')
                    ->collapsible(),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    /**
     * @return array<int, string>
     */
    private static function profileOptions(): array
    {
        return DeviceProfile::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function profileVersionOptions(mixed $livewire): array
    {
        $deviceProfileId = data_get($livewire, 'tableFilters.device_profile_id.value');

        if (! is_numeric($deviceProfileId)) {
            return [];
        }

        return DeviceProfileVersion::query()
            ->where('device_profile_id', (int) $deviceProfileId)
            ->orderBy('version')
            ->get()
            ->mapWithKeys(fn (DeviceProfileVersion $profileVersion): array => [
                $profileVersion->id => 'v'.$profileVersion->version,
            ])
            ->all();
    }
}
