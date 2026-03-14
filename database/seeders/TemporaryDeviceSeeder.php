<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\TemporaryDevice;
use Illuminate\Database\Seeder;

class TemporaryDeviceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        TemporaryDevice::factory()->count(5)->create();
    }
}
