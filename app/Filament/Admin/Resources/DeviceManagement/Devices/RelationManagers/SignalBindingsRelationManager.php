<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceManagement\Devices\RelationManagers;

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Filament\Actions;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SignalBindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'signalBindings';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('device_channel_id')
                    ->label('Profile channel')
                    ->options(fn (): array => $this->channelOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('parameter_key', null);
                    }),
                Select::make('parameter_key')
                    ->label('Profile parameter')
                    ->options(fn (Get $get): array => $this->parameterOptions($get('device_channel_id')))
                    ->searchable()
                    ->required(),
                TextInput::make('source_topic')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('source_json_path')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('$.io_1_value'),
                TextInput::make('source_adapter')
                    ->maxLength(100)
                    ->placeholder('imoni'),
                TextInput::make('sequence')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('is_active')
                    ->default(true),
                KeyValue::make('metadata')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('deviceChannel'))
            ->recordTitleAttribute('source_topic')
            ->columns([
                TextColumn::make('deviceChannel.label')
                    ->label('Channel')
                    ->searchable(),
                TextColumn::make('parameter_key')
                    ->label('Parameter')
                    ->badge(),
                TextColumn::make('source_topic')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('source_json_path')
                    ->label('JSON path')
                    ->copyable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->slideOver()
                    ->modalWidth('4xl')
                    ->authorize(fn (): bool => $this->canManageBindings())
                    ->visible(fn (): bool => $this->canManageBindings())
                    ->mutateFormDataUsing(fn (array $data): array => $this->validatedBindingData($data)),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth('4xl')
                    ->authorize(fn (): bool => $this->canManageBindings())
                    ->visible(fn (): bool => $this->canManageBindings())
                    ->mutateFormDataUsing(fn (array $data, DeviceSignalBinding $record): array => $this->validatedBindingData($data, $record)),
                Actions\DeleteAction::make()
                    ->authorize(fn (): bool => $this->canManageBindings())
                    ->visible(fn (): bool => $this->canManageBindings()),
            ])
            ->defaultSort('sequence');
    }

    /**
     * @return array<int, string>
     */
    private function channelOptions(): array
    {
        $device = $this->ownerRecord;

        if (! $device instanceof Device || ! is_numeric($device->device_profile_version_id)) {
            return [];
        }

        return DeviceChannel::query()
            ->where('device_profile_version_id', (int) $device->device_profile_version_id)
            ->where('direction', ChannelDirection::Publish->value)
            ->orderBy('sequence')
            ->pluck('label', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function parameterOptions(mixed $channelId): array
    {
        if (! is_numeric($channelId)) {
            return [];
        }

        return ProfileParameterDefinition::query()
            ->where('device_channel_id', (int) $channelId)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get(['key', 'label'])
            ->mapWithKeys(fn (ProfileParameterDefinition $parameter): array => [
                $parameter->key => "{$parameter->label} ({$parameter->key})",
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validatedBindingData(array $data, ?DeviceSignalBinding $record = null): array
    {
        $device = $this->ownerRecord;

        if (! $device instanceof Device || ! is_numeric($device->device_profile_version_id)) {
            throw ValidationException::withMessages([
                'device_channel_id' => 'Select a device profile version before adding signal bindings.',
            ]);
        }

        $channel = DeviceChannel::query()
            ->whereKey($data['device_channel_id'] ?? null)
            ->where('device_profile_version_id', (int) $device->device_profile_version_id)
            ->where('direction', ChannelDirection::Publish->value)
            ->first();

        if (! $channel instanceof DeviceChannel) {
            throw ValidationException::withMessages([
                'device_channel_id' => 'The selected channel must belong to the device profile version.',
            ]);
        }

        $parameterExists = ProfileParameterDefinition::query()
            ->where('device_channel_id', $channel->id)
            ->where('key', $data['parameter_key'] ?? null)
            ->where('is_active', true)
            ->exists();

        if (! $parameterExists) {
            throw ValidationException::withMessages([
                'parameter_key' => 'The selected parameter must belong to the selected channel.',
            ]);
        }

        $duplicateExists = DeviceSignalBinding::query()
            ->where('device_id', $device->id)
            ->where('device_channel_id', $channel->id)
            ->where('parameter_key', $data['parameter_key'])
            ->when($record instanceof DeviceSignalBinding, fn (Builder $query) => $query->whereKeyNot($record->id))
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'parameter_key' => 'This device already has a binding for the selected channel parameter.',
            ]);
        }

        return $data;
    }

    private function canManageBindings(): bool
    {
        return $this->ownerRecord instanceof Device;
    }
}
