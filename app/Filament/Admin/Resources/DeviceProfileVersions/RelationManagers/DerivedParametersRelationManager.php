<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions\RelationManagers;

use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Filament\Actions;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DerivedParametersRelationManager extends RelationManager
{
    protected static string $relationship = 'derivedParameters';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Derived parameter')
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(100)
                            ->regex('/^[a-z0-9_-]+$/'),
                        TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        Select::make('data_type')
                            ->options(ParameterDataType::class)
                            ->default(ParameterDataType::Decimal->value)
                            ->required(),
                        TextInput::make('unit')
                            ->maxLength(50),
                        TextInput::make('json_path')
                            ->maxLength(255)
                            ->placeholder('derived.energy_per_meter'),
                        TagsInput::make('dependencies')
                            ->placeholder('temperature'),
                        CodeEditor::make('expression')
                            ->language(Language::Json)
                            ->rules(['required', 'json'])
                            ->formatStateUsing(fn (mixed $state): string => self::encodeJson($state))
                            ->dehydrateStateUsing(fn (mixed $state): array => self::decodeJson($state) ?? [])
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
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
                TextColumn::make('data_type')
                    ->badge(),
                TextColumn::make('unit')
                    ->placeholder('—'),
                TextColumn::make('dependencies')
                    ->badge()
                    ->separator(','),
                TextColumn::make('json_path')
                    ->placeholder('—'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->slideOver()
                    ->modalWidth('5xl')
                    ->visible(fn (): bool => $this->ownerRecord instanceof DeviceProfileVersion && $this->ownerRecord->canEditContract()),
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth('5xl'),
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth('5xl')
                    ->visible(fn (): bool => $this->ownerRecord instanceof DeviceProfileVersion && $this->ownerRecord->canEditContract()),
                Actions\DeleteAction::make()
                    ->visible(fn (): bool => $this->ownerRecord instanceof DeviceProfileVersion && $this->ownerRecord->canEditContract()),
            ]);
    }

    private static function encodeJson(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return '';
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
