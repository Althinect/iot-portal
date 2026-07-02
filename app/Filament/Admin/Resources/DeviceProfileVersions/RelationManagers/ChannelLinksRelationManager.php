<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions\RelationManagers;

use App\Domain\DeviceProfile\Enums\ChannelLinkType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceChannelLink;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChannelLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'channelLinks';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components($this->linkFormComponents());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('link_type')
            ->columns([
                TextColumn::make('fromChannel.label')
                    ->label('From'),
                TextColumn::make('toChannel.label')
                    ->label('To'),
                TextColumn::make('link_type')
                    ->badge(),
            ])
            ->headerActions([
                Actions\Action::make('create')
                    ->label('Create')
                    ->schema($this->linkFormComponents())
                    ->slideOver()
                    ->modalWidth('3xl')
                    ->visible(fn (): bool => $this->ownerRecord instanceof DeviceProfileVersion && $this->ownerRecord->canEditContract())
                    ->action(fn (array $data): DeviceChannelLink => DeviceChannelLink::query()->create($data)),
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth('3xl'),
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth('3xl')
                    ->visible(fn (): bool => $this->ownerRecord instanceof DeviceProfileVersion && $this->ownerRecord->canEditContract()),
                Actions\DeleteAction::make()
                    ->visible(fn (): bool => $this->ownerRecord instanceof DeviceProfileVersion && $this->ownerRecord->canEditContract()),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function linkFormComponents(): array
    {
        return [
            Select::make('from_device_channel_id')
                ->label('Command channel')
                ->options(fn (): array => $this->channelOptions())
                ->searchable()
                ->required(),
            Select::make('to_device_channel_id')
                ->label('Feedback channel')
                ->options(fn (): array => $this->channelOptions())
                ->searchable()
                ->required()
                ->different('from_device_channel_id'),
            Select::make('link_type')
                ->options(ChannelLinkType::class)
                ->required(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function channelOptions(): array
    {
        if (! $this->ownerRecord instanceof DeviceProfileVersion) {
            return [];
        }

        return DeviceChannel::query()
            ->where('device_profile_version_id', $this->ownerRecord->id)
            ->orderBy('sequence')
            ->pluck('label', 'id')
            ->all();
    }
}
