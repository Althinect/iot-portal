<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfiles\RelationManagers;

use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use App\Filament\Admin\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use App\Filament\Admin\Resources\DeviceProfileVersions\Schemas\DeviceProfileVersionForm;
use App\Filament\Admin\Resources\DeviceProfileVersions\Tables\DeviceProfileVersionsTable;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public function form(Schema $schema): Schema
    {
        return DeviceProfileVersionForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return DeviceProfileVersionsTable::configure($table)
            ->recordTitleAttribute('version')
            ->headerActions([
                Actions\Action::make('draftFromLatest')
                    ->label('Draft from latest')
                    ->icon('heroicon-o-square-2-stack')
                    ->visible(fn (): bool => $this->ownerRecord instanceof DeviceProfile && $this->ownerRecord->versions()->exists())
                    ->action(function (): void {
                        if (! $this->ownerRecord instanceof DeviceProfile) {
                            return;
                        }

                        $source = $this->ownerRecord->versions()->latest('version')->first();

                        if (! $source instanceof DeviceProfileVersion) {
                            return;
                        }

                        app(DeviceProfileVersionLifecycleService::class)->cloneAsDraft($source);
                    }),
            ])
            ->recordUrl(fn (DeviceProfileVersion $record): string => DeviceProfileVersionResource::getUrl('edit', ['record' => $record]));
    }
}
