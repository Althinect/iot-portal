<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfiles\RelationManagers;

use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use App\Filament\Portal\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $relatedResource = DeviceProfileVersionResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                if ($this->ownerRecord instanceof DeviceProfile && $this->ownerRecord->isGlobal()) {
                    return $query->where('status', DeviceProfileVersion::STATUS_ACTIVE);
                }

                return $query;
            })
            ->headerActions([
                Actions\Action::make('createDraft')
                    ->label('Create draft version')
                    ->icon('heroicon-o-plus')
                    ->schema([
                        Select::make('protocol')
                            ->options(Protocol::class)
                            ->default(Protocol::Mqtt->value)
                            ->required(),
                        Textarea::make('notes')
                            ->label('Contract notes')
                            ->rows(3),
                    ])
                    ->visible(fn (): bool => $this->canManageOwner())
                    ->action(function (array $data): DeviceProfileVersion {
                        abort_unless($this->ownerRecord instanceof DeviceProfile, 404);
                        Gate::authorize('update', $this->ownerRecord);

                        return app(DeviceProfileVersionLifecycleService::class)->createDraftForProfile(
                            $this->ownerRecord,
                            [
                                'protocol' => Protocol::tryFrom((string) ($data['protocol'] ?? '')) ?? Protocol::Mqtt,
                                'protocol_config' => null,
                                'notes' => is_string($data['notes'] ?? null) ? $data['notes'] : null,
                            ],
                        );
                    }),
            ]);
    }

    private function canManageOwner(): bool
    {
        return $this->ownerRecord instanceof DeviceProfile
            && ! $this->ownerRecord->isGlobal()
            && Gate::allows('update', $this->ownerRecord)
            && Gate::allows('create', DeviceProfileVersion::class);
    }
}
