<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\IoTDashboards\Pages;

use App\Filament\Portal\Pages\IoTDashboard as IoTDashboardPage;
use App\Filament\Portal\Resources\IoTDashboards\IoTDashboardResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewIoTDashboard extends ViewRecord
{
    protected static string $resource = IoTDashboardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openDashboard')
                ->label('Open Dashboard')
                ->icon(Heroicon::OutlinedPresentationChartLine)
                ->color('success')
                ->url(fn (): string => IoTDashboardPage::getUrl(
                    parameters: ['dashboard' => $this->getRecord()->getKey()],
                    panel: 'portal',
                    tenant: Filament::getTenant(),
                )),
        ];
    }
}
