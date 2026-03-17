<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Contracts;

use App\Domain\IoTDashboard\Enums\WidgetType;

interface WidgetConfig
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): static;

    public function type(): WidgetType;

    /**
     * @return array<int, array{key: string, label: string, color: string, unit?: string|null}>
     */
    public function series(): array;

    public function useWebsocket(): bool;

    public function usePolling(): bool;

    public function pollingIntervalSeconds(): int;

    public function lookbackMinutes(): int;

    public function maxPoints(): int;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * @return array<string, mixed>
     */
    public function meta(): array;
}
