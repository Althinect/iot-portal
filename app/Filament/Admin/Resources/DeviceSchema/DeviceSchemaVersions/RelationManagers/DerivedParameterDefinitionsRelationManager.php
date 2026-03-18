<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceSchema\DeviceSchemaVersions\RelationManagers;

use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DerivedParameterDefinition;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class DerivedParameterDefinitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'derivedParameters';

    public function getOwnerRecord(): DeviceSchemaVersion
    {
        /** @var DeviceSchemaVersion $ownerRecord */
        $ownerRecord = $this->ownerRecord;

        return $ownerRecord;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()
                    ->maxLength(100)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: function (Unique $rule): Unique {
                            /** @var int|string $ownerKey */
                            $ownerKey = $this->getOwnerRecord()->getKey();

                            return $rule->where('device_schema_version_id', $ownerKey);
                        },
                    ),

                TextInput::make('label')
                    ->required()
                    ->maxLength(255),

                Select::make('data_type')
                    ->options(ParameterDataType::class)
                    ->required(),

                TextInput::make('unit')
                    ->maxLength(50)
                    ->placeholder('Watts'),

                CheckboxList::make('dependencies')
                    ->helperText('Select parameters used in the expression')
                    ->columnSpanFull()
                    ->options(fn (): array => $this->getOwnerRecord()
                        ->parameters()
                        ->orderBy('parameter_definitions.sequence')
                        ->pluck('parameter_definitions.key', 'parameter_definitions.key')
                        ->all())
                    ->columns(4),

                CodeEditor::make('expression')
                    ->language(Language::Json)
                    ->required()
                    ->columnSpanFull()
                    ->helperText('JsonLogic expression for derived values')
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if ($value === null || $value === '') {
                                return;
                            }

                            $expression = is_string($value) ? json_decode($value, true) : $value;

                            if (! is_array($expression)) {
                                $fail('Expression must be valid JSON.');

                                return;
                            }

                            $variables = DerivedParameterDefinition::extractVariablesFromExpression($expression);
                            $dependencies = $get('dependencies') ?? [];

                            if (! is_array($dependencies)) {
                                $dependencies = [];
                            }

                            $dependencyKeys = array_values(array_filter(
                                array_map(
                                    static fn (mixed $dependency): ?string => is_string($dependency) ? $dependency : null,
                                    $dependencies,
                                ),
                                static fn (?string $dependency): bool => $dependency !== null && $dependency !== '',
                            ));

                            $missing = array_values(array_diff($variables, $dependencyKeys));

                            if ($missing !== []) {
                                $fail('Select dependencies for: '.implode(', ', $missing));
                            }
                        },
                    ])
                    ->formatStateUsing(function (mixed $state): ?string {
                        if (is_array($state)) {
                            $encoded = json_encode($state, JSON_PRETTY_PRINT);

                            return $encoded === false ? null : $encoded;
                        }

                        return is_string($state) ? $state : null;
                    })
                    ->dehydrateStateUsing(fn (?string $state): mixed => $state ? json_decode($state, true) : null),

                TextInput::make('json_path')
                    ->label('JSON Path')
                    ->maxLength(255)
                    ->placeholder('computed.avg_voltage'),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('key')
            ->columns([
                TextColumn::make('key')
                    ->searchable(),

                TextColumn::make('label')
                    ->searchable(),

                TextColumn::make('data_type')
                    ->badge()
                    ->formatStateUsing(fn (ParameterDataType|string $state): string => $state instanceof ParameterDataType ? $state->label() : (string) $state),

                TextColumn::make('dependencies')
                    ->label('Dependencies')
                    ->formatStateUsing(fn (mixed $state): string => is_array($state)
                        ? implode(', ', array_values(array_filter(
                            array_map(
                                static fn (mixed $dependency): ?string => is_string($dependency) ? $dependency : null,
                                $state,
                            ),
                            static fn (?string $dependency): bool => $dependency !== null && $dependency !== '',
                        )))
                        : ''),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
