<?php

declare(strict_types=1);

namespace App\Filament\Actions\DeviceManagement;

use App\Domain\DeviceManagement\Models\Device;
use Filament\Actions\Action;
use Filament\Infolists\Components\CodeEntry;
use Filament\Support\Icons\Heroicon;
use Phiki\Grammar\Grammar;

final class ViewFirmwareAction
{
    public static function make(): Action
    {
        return Action::make('viewFirmware')
            ->label('View Firmware')
            ->icon(Heroicon::OutlinedCodeBracketSquare)
            ->modalHeading('Rendered Firmware')
            ->modalWidth('7xl')
            ->slideOver()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema([
                CodeEntry::make('firmware')
                    ->label('Firmware')
                    ->grammar(Grammar::Cpp)
                    // ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage('Firmware copied')
                    ->copyableState(fn (Device $record): string => $record->profileVersion?->renderFirmwareForDevice($record)
                        ?? '// No firmware template is configured for this device profile version.')
                    ->state(
                        fn (Device $record): string => $record->profileVersion?->renderFirmwareForDevice($record)
                            ?? '// No firmware template is configured for this device profile version.'
                    )
                    // ->extraAttributes(['class' => 'whitespace-pre-wrap'])
                    ->columnSpanFull(),
            ]);
    }
}
