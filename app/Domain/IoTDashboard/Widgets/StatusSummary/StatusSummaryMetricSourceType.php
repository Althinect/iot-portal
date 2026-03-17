<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StatusSummary;

enum StatusSummaryMetricSourceType: string
{
    case LatestParameter = 'latest_parameter';
}
