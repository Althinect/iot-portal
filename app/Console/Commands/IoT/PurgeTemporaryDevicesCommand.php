<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DeviceManagement\Models\TemporaryDevice;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class PurgeTemporaryDevicesCommand extends Command
{
    protected $signature = 'iot:purge-temporary-devices';

    protected $description = 'Permanently delete expired temporary devices';

    public function handle(): int
    {
        $purgedDeviceCount = 0;

        TemporaryDevice::query()
            ->expired()
            ->with([
                'device' => fn ($query) => $query->withTrashed(),
            ])
            ->orderBy('id')
            ->chunkById(100, function (Collection $temporaryDevices) use (&$purgedDeviceCount): void {
                foreach ($temporaryDevices as $temporaryDevice) {
                    $device = $temporaryDevice->device;

                    if ($device === null) {
                        $temporaryDevice->delete();

                        continue;
                    }

                    $device->forceDelete();
                    $purgedDeviceCount++;
                }
            });

        $this->info("Purged {$purgedDeviceCount} expired temporary device(s).");

        return self::SUCCESS;
    }
}
