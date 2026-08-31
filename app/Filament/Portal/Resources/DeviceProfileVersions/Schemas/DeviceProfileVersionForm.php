<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfileVersions\Schemas;

use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DeviceProfileVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contract version')
                ->description('Only the tenant-facing contract is editable here. Transport endpoints, authentication, firmware, and ingestion configuration remain platform managed.')
                ->schema([
                    Select::make('device_profile_id')
                        ->label('Private profile')
                        ->options(function (): array {
                            $tenantId = Filament::getTenant()?->getKey();

                            return DeviceProfile::query()
                                ->when(
                                    is_numeric($tenantId),
                                    fn (Builder $query): Builder => $query->where('organization_id', (int) $tenantId),
                                    fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
                                )
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->required()
                        ->searchable()
                        ->preload()
                        ->disabled(fn (?DeviceProfileVersion $record): bool => $record !== null)
                        ->dehydrated(fn (?DeviceProfileVersion $record): bool => $record === null),
                    Select::make('protocol')
                        ->options(Protocol::class)
                        ->default(Protocol::Mqtt->value)
                        ->required(),
                    Textarea::make('notes')
                        ->label('Contract notes')
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
