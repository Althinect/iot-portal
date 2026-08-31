<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfileVersions\RelationManagers;

use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Filament\Actions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Channel contract')
                    ->description('Define what the device sends or receives. Transport addresses and delivery settings are managed separately by the platform.')
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(100)
                            ->regex('/^[a-z0-9_-]+$/'),
                        TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        Select::make('direction')
                            ->options(ChannelDirection::class)
                            ->required(),
                        Select::make('purpose')
                            ->options(ChannelPurpose::class)
                            ->required(),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Repeater::make('parameters')
                    ->relationship()
                    ->label('Parameter contract')
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['key'] ?? null)
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(100)
                            ->regex('/^[a-z0-9_-]+$/')
                            ->columnSpan(4),
                        TextInput::make('label')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(4),
                        TextInput::make('json_path')
                            ->label('JSON path')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(4),
                        Select::make('type')
                            ->options(ParameterDataType::class)
                            ->default(ParameterDataType::Decimal->value)
                            ->required()
                            ->columnSpan(3),
                        Select::make('category')
                            ->options(ParameterCategory::class)
                            ->default(ParameterCategory::Measurement->value)
                            ->required()
                            ->columnSpan(3),
                        TextInput::make('unit')
                            ->maxLength(50)
                            ->columnSpan(2),
                        Toggle::make('required')
                            ->default(false)
                            ->columnSpan(2),
                        Toggle::make('is_critical')
                            ->label('Critical')
                            ->default(false)
                            ->columnSpan(1),
                        Toggle::make('is_active')
                            ->label('Enabled')
                            ->default(true)
                            ->columnSpan(1),
                    ])
                    ->columns(12)
                    ->collapsible()
                    ->cloneable()
                    ->reorderable()
                    ->orderColumn('sequence')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record): string => (string) $record->key),
                TextColumn::make('direction')
                    ->badge(),
                TextColumn::make('purpose')
                    ->badge(),
                TextColumn::make('parameters_count')
                    ->counts('parameters')
                    ->label('Parameters'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->slideOver()
                    ->modalWidth('5xl')
                    ->visible(fn (): bool => $this->canEditContract())
                    ->mutateDataUsing(fn (array $data): array => $this->normalizeCreateData($data)),
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth('5xl'),
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth('5xl')
                    ->visible(fn (): bool => $this->canEditContract())
                    ->mutateDataUsing(fn (array $data): array => $this->normalizeContractData($data)),
                Actions\DeleteAction::make()
                    ->visible(fn (): bool => $this->canEditContract()),
            ])
            ->defaultSort('sequence');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeCreateData(array $data): array
    {
        abort_unless($this->canEditContract(), 403);
        abort_unless($this->ownerRecord instanceof DeviceProfileVersion, 404);

        $contract = $this->normalizeContractData($data);
        $key = is_string($contract['key'] ?? null) ? $contract['key'] : 'channel';
        $isHttp = $this->ownerRecord->protocol === Protocol::Http;

        return [
            ...$contract,
            'transport' => $isHttp ? ChannelTransport::Http : ChannelTransport::Mqtt,
            'address' => $key,
            'http_method' => $isHttp ? 'POST' : '',
            'qos' => $isHttp ? 0 : 1,
            'retain' => false,
            'options' => null,
            'sequence' => ((int) $this->ownerRecord->channels()->max('sequence')) + 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeContractData(array $data): array
    {
        abort_unless($this->canEditContract(), 403);

        $contract = Arr::only($data, [
            'key',
            'label',
            'direction',
            'purpose',
            'description',
            'parameters',
        ]);

        if (! is_array($contract['parameters'] ?? null)) {
            $contract['parameters'] = [];

            return $contract;
        }

        $contract['parameters'] = collect($contract['parameters'])
            ->map(function (mixed $parameter): array {
                if (! is_array($parameter)) {
                    return [];
                }

                return Arr::only($parameter, [
                    'key',
                    'label',
                    'json_path',
                    'type',
                    'category',
                    'unit',
                    'required',
                    'is_critical',
                    'is_active',
                    'sequence',
                ]);
            })
            ->filter()
            ->all();

        return $contract;
    }

    private function canEditContract(): bool
    {
        return $this->ownerRecord instanceof DeviceProfileVersion
            && $this->ownerRecord->canEditContract()
            && Gate::allows('update', $this->ownerRecord);
    }
}
