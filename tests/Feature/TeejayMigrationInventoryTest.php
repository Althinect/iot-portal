<?php

declare(strict_types=1);

use Database\Seeders\TeejayMigrationInventory;

it('matches the current Teejay legacy physical device inventory', function (): void {
    $types = [
        'AC Energy Mate' => 102,
        'Fabric Length' => 14,
        'Fabric Length(Short)' => 8,
        'IMoni Modbus Level Sensor' => 9,
        'Preassure' => 12,
        'Status' => 32,
        'Steam meter' => 14,
        'Temperature' => 16,
        'Water Flow and Volume' => 28,
    ];

    foreach ($types as $type => $expectedCount) {
        expect(TeejayMigrationInventory::devicesForType($type))->toHaveCount($expectedCount);
    }
});

it('preserves current Teejay gateway assignments', function (): void {
    $energyDevices = collect(TeejayMigrationInventory::devicesForType('AC Energy Mate'));
    $statusDevices = collect(TeejayMigrationInventory::devicesForType('Status'));

    expect($energyDevices->firstWhere('legacy_virtual_device_id', '8122829d-8edf-486d-9d18-1eccb66f9e38'))
        ->toMatchArray([
            'name' => 'TJ-WTP/ ETP',
            'hub_imei' => '869604063874209',
        ])
        ->and($statusDevices->firstWhere('name', 'Final Inspection 5'))
        ->toMatchArray([
            'hub_imei' => '8865286073149329',
            'legacy_device_uid' => '869604063846025-00-01',
        ]);
});
