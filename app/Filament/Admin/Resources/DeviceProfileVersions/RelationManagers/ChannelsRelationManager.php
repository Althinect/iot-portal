<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions\RelationManagers;

use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Filament\Actions;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Channel')
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
                            ->required()
                            ->live(),
                        Select::make('purpose')
                            ->options(ChannelPurpose::class),
                        Select::make('transport')
                            ->options(ChannelTransport::class)
                            ->default(ChannelTransport::Mqtt->value)
                            ->required(),
                        TextInput::make('address')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('http_method')
                            ->label('HTTP method')
                            ->maxLength(10)
                            ->default(''),
                        TextInput::make('qos')
                            ->label('QoS')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(2)
                            ->default(1),
                        Toggle::make('retain')
                            ->default(false),
                        Textarea::make('description')
                            ->columnSpanFull(),
                        KeyValue::make('options')
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
                Repeater::make('parameters')
                    ->relationship()
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(100)
                            ->regex('/^[a-z0-9_-]+$/'),
                        TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('json_path')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->options(ParameterDataType::class)
                            ->default(ParameterDataType::Decimal->value)
                            ->required(),
                        Select::make('category')
                            ->options(ParameterCategory::class)
                            ->default(ParameterCategory::Measurement->value)
                            ->required(),
                        TextInput::make('unit')
                            ->maxLength(50),
                        Toggle::make('required')
                            ->default(false),
                        Toggle::make('is_critical')
                            ->label('Critical')
                            ->default(false),
                        Toggle::make('is_active')
                            ->default(true),
                        KeyValue::make('validation_rules')
                            ->columnSpanFull(),
                        KeyValue::make('control_ui')
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
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
                    ->badge()
                    ->placeholder('Auto'),
                TextColumn::make('transport')
                    ->badge(),
                TextColumn::make('address')
                    ->searchable(),
                TextColumn::make('parameters_count')
                    ->counts('parameters')
                    ->label('Parameters'),
                IconColumn::make('retain')
                    ->boolean(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->visible(fn (): bool => $this->ownerRecord instanceof DeviceProfileVersion && $this->ownerRecord->canEditContract()),
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth('7xl'),
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->visible(fn (): bool => $this->ownerRecord instanceof DeviceProfileVersion && $this->ownerRecord->canEditContract()),
                Actions\DeleteAction::make()
                    ->visible(fn (): bool => $this->ownerRecord instanceof DeviceProfileVersion && $this->ownerRecord->canEditContract()),
            ])
            ->reorderable('sequence')
            ->defaultSort('sequence');
    }
}
