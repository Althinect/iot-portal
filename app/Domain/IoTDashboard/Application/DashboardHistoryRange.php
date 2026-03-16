<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Application;

use Carbon\CarbonImmutable;

final class DashboardHistoryRange
{
    public function __construct(
        private readonly CarbonImmutable $fromAt,
        private readonly CarbonImmutable $untilAt,
    ) {}

    public function fromAt(): CarbonImmutable
    {
        return $this->fromAt;
    }

    public function untilAt(): CarbonImmutable
    {
        return $this->untilAt;
    }
}
