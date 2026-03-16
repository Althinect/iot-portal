<?php

use App\Providers\AppServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PortalPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IoTDashboardServiceProvider;

return [
    AppServiceProvider::class,
    FeatureServiceProvider::class,
    IoTDashboardServiceProvider::class,
    AdminPanelProvider::class,
    PortalPanelProvider::class,
    HorizonServiceProvider::class,
];
