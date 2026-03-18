<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function runImoniFlow(array $payload): array
{
    $flow = collect(json_decode(
        file_get_contents(base_path('docker/node-red/data/flows.json')) ?: '[]',
        true,
        512,
        JSON_THROW_ON_ERROR,
    ));

    $functions = $flow
        ->filter(fn (array $node): bool => ($node['type'] ?? null) === 'function')
        ->mapWithKeys(fn (array $node): array => [$node['name'] => $node['func']])
        ->all();

    $script = <<<'JS'
const functions = JSON.parse(process.argv[1]);
const input = JSON.parse(process.argv[2]);

function runNode(name, message) {
    const flow = { get: () => false };
    const context = { flow };
    const fn = new Function('msg', 'flow', 'context', functions[name]);
    return fn(message, flow, context);
}

const ingressResult = runNode('Ingress Guard', { payload: input });
const processMessage = ingressResult[2];
const decoded = runNode('IMoni Decoding Script', processMessage);
const packetResult = runNode('Packet Type Guard', decoded);

if (packetResult[0] === null) {
    console.log(JSON.stringify({
        response: ingressResult[0]?.payload ?? null,
        diagnostics: packetResult[1]?.payload ?? null,
        published: [],
        summary: null,
    }));
    process.exit(0);
}

const profiled = runNode('Profile Resolve', packetResult[0]);
const transformed = runNode('Transform Engine', profiled);
const normalized = runNode('Normalize To MQTT', transformed);

console.log(JSON.stringify({
    response: ingressResult[0]?.payload ?? null,
    published: normalized[0] ?? [],
    summary: normalized[1]?.payload ?? null,
}));
JS;

    $process = new Process([
        'node',
        '-e',
        $script,
        json_encode($functions, JSON_THROW_ON_ERROR),
        json_encode($payload, JSON_THROW_ON_ERROR),
    ], base_path());

    $process->mustRun();

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}

it('normalizes iMoni peripheral payloads into source topics for laravel-side bindings', function (): void {
    $samplePayload = [
        'imei' => '869244049087921',
        'deviceId' => '869244049087921',
        'type' => 'data',
        'timestamp' => '2026/03/16 12:25:36',
        'firmwareVersion' => '96',
        'packetType' => '13',
        'packetDescription' => 'PeripheralData',
        'segmentCount' => '03',
        'dataLength' => '00B4',
        'peripheralDataArr' => [
            'iMoni_LITE' => [
                '1' => [1, '0101015B', 'readAnalogIN', 347],
                '2' => [2, '0102015E', 'readAnalogIN', 350],
                '3' => [3, '0103015A', 'readAnalogIN', 346],
            ],
            'IOext1' => [
                '1' => [1, '022101', 'readDigitalIN', 1],
                '2' => [2, '022201', 'readDigitalIN', 1],
                '5' => [5, '01050000', 'readAnalogIN', 0],
            ],
            'IOext2' => [
                '1' => [1, '022101', 'readDigitalIN', 1],
                '5' => [5, '010501EC', 'readAnalogIN', 492],
            ],
        ],
    ];

    $result = runImoniFlow($samplePayload);

    $topics = collect($result['published'])->pluck('topic')->all();
    $childPayloads = collect($result['published'])
        ->filter(fn (array $message): bool => str_contains($message['topic'], '/telemetry'))
        ->pluck('payload');

    expect($result['response'])->toMatchArray([
        'accepted' => true,
    ])->and($topics)->toBe([
        'devices/869244049087921/presence',
        'migration/source/imoni/869244049087921/00/telemetry',
        'migration/source/imoni/869244049087921/11/telemetry',
        'migration/source/imoni/869244049087921/12/telemetry',
    ])->and($result['summary'])->toMatchArray([
        'route' => 'source_peripheral_topics',
        'published_child_count' => 3,
        'published_children' => [
            '869244049087921:00',
            '869244049087921:11',
            '869244049087921:12',
        ],
    ])->and($childPayloads->pluck('_meta.source_key')->all())->toBe([
        '869244049087921:00',
        '869244049087921:11',
        '869244049087921:12',
    ])->and($childPayloads->pluck('_meta.peripheral_type_hex')->all())->toBe([
        '00',
        '11',
        '12',
    ])->and(data_get($childPayloads->first(), 'object_values.1.value'))->toBe(347)
        ->and(data_get($childPayloads->first(), 'object_values.2.value'))->toBe(350)
        ->and(data_get($childPayloads->first(), 'object_values.3.value'))->toBe(346)
        ->and(data_get($childPayloads->first(), 'io_1_value'))->toBe(347)
        ->and(data_get($childPayloads->first(), 'io_2_value'))->toBe(350)
        ->and(data_get($childPayloads->first(), 'io_3_value'))->toBe(346);
});

it('preserves named peripheral object keys in a structured source payload', function (): void {
    $samplePayload = [
        'imei' => '869244041759394',
        'deviceId' => '869244041759394',
        'type' => 'data',
        'timestamp' => '2026/03/18 12:25:36',
        'firmwareVersion' => '96',
        'packetType' => '13',
        'packetDescription' => 'PeripheralData',
        'segmentCount' => '01',
        'dataLength' => '0020',
        'peripheralDataArr' => [
            'AC_energyMate1' => [
                'TotalEnergy' => ['TotalEnergy', '1B00000001', 'read64bitdata', 451.2],
                'ActivePowerA' => ['ActivePowerA', '1B00000002', 'read64bitdata', 1820.4],
                'PhaseAVoltage' => ['PhaseAVoltage', '1B00000003', 'read64bitdata', 230.4],
            ],
        ],
    ];

    $result = runImoniFlow($samplePayload);

    $sourcePayload = data_get($result['published'], '1.payload');
    $sourceKeys = collect($sourcePayload ?? [])->keys()->filter(
        fn (string $key): bool => str_starts_with($key, 'io_NaN_'),
    );

    expect(data_get($result['published'], '1.topic'))->toBe('migration/source/imoni/869244041759394/21/telemetry')
        ->and(data_get($sourcePayload, 'object_values.TotalEnergy.value'))->toBe(451.2)
        ->and(data_get($sourcePayload, 'object_values.ActivePowerA.value'))->toBe(1820.4)
        ->and(data_get($sourcePayload, 'object_values.PhaseAVoltage.value'))->toBe(230.4)
        ->and(data_get($sourcePayload, 'io_1_value'))->toBeNull()
        ->and($sourceKeys->all())->toBe([]);
});

it('publishes both numeric aliases and named object values for mixed peripherals without io_NaN aliases', function (): void {
    $samplePayload = [
        'imei' => '869244041759394',
        'deviceId' => '869244041759394',
        'type' => 'data',
        'timestamp' => '2026/03/18 12:25:36',
        'firmwareVersion' => '96',
        'packetType' => '13',
        'packetDescription' => 'PeripheralData',
        'segmentCount' => '01',
        'dataLength' => '0020',
        'peripheralDataArr' => [
            'AC_energyMate1' => [
                '1' => [1, '0101015B', 'readAnalogIN', 2300],
                '2' => [2, '0102015E', 'readAnalogIN', 2310],
                'TotalEnergy' => ['TotalEnergy', '1B00000001', 'read64bitdata', 451.2],
                'TotalActivePower' => ['TotalActivePower', '1B00000002', 'read64bitdata', 1820.4],
            ],
        ],
    ];

    $result = runImoniFlow($samplePayload);

    $sourcePayload = data_get($result['published'], '1.payload');
    $invalidAliasKeys = collect($sourcePayload ?? [])->keys()->filter(
        fn (string $key): bool => str_starts_with($key, 'io_NaN_'),
    );

    expect(data_get($result['published'], '1.topic'))->toBe('migration/source/imoni/869244041759394/21/telemetry')
        ->and(data_get($sourcePayload, 'io_1_value'))->toBe(2300)
        ->and(data_get($sourcePayload, 'io_2_value'))->toBe(2310)
        ->and(data_get($sourcePayload, 'object_values.1.value'))->toBe(2300)
        ->and(data_get($sourcePayload, 'object_values.2.value'))->toBe(2310)
        ->and(data_get($sourcePayload, 'object_values.TotalEnergy.value'))->toBe(451.2)
        ->and(data_get($sourcePayload, 'object_values.TotalActivePower.value'))->toBe(1820.4)
        ->and($invalidAliasKeys->all())->toBe([]);
});

it('publishes only hub presence for heartbeat payloads', function (): void {
    $heartbeatPayload = [
        'imei' => '869244049087921',
        'deviceId' => '869244049087921',
        'type' => 'heartbeat',
        'timestamp' => '2026/03/16 12:25:36',
        'firmwareVersion' => '96',
        'packetType' => '1A',
        'packetDescription' => 'heartBeat',
        'segmentCount' => '00',
        'dataLength' => '0000',
        'peripheralDataArr' => [],
    ];

    $result = runImoniFlow($heartbeatPayload);

    expect($result['published'])->toHaveCount(1)
        ->and($result['published'][0]['topic'])->toBe('devices/869244049087921/presence')
        ->and($result['summary'])->toMatchArray([
            'published_child_count' => 0,
            'published_children' => [],
        ]);
});

it('normalizes witco hub traffic into generic source topics for laravel-side bindings', function (): void {
    $samplePayload = [
        'imei' => '869244041754866',
        'deviceId' => '869244041754866',
        'type' => 'data',
        'timestamp' => '2026/03/16 12:25:36',
        'firmwareVersion' => '96',
        'packetType' => '13',
        'packetDescription' => 'PeripheralData',
        'segmentCount' => '01',
        'dataLength' => '0020',
        'peripheralDataArr' => [
            'iMoni_LITE' => [
                '1' => [1, '022101', 'readDigitalIN', 0],
                '2' => [2, '022201', 'readDigitalIN', 1],
                '3' => [3, '022301', 'readDigitalIN', 0],
            ],
        ],
    ];

    $result = runImoniFlow($samplePayload);

    $publishedMessages = collect($result['published']);
    $payloadsByTopic = $publishedMessages->keyBy('topic');
    $sourcePayload = data_get($payloadsByTopic, 'migration/source/imoni/869244041754866/00/telemetry.payload');

    expect($publishedMessages->pluck('topic')->all())->toBe([
        'devices/869244041754866/presence',
        'migration/source/imoni/869244041754866/00/telemetry',
    ])->and($sourcePayload)->toBeArray()
        ->and($sourcePayload['io_2_value'] ?? null)->toBe(1)
        ->and($sourcePayload['io_3_value'] ?? null)->toBe(0)
        ->and(data_get($sourcePayload, 'object_values.2.value'))->toBe(1)
        ->and(data_get($sourcePayload, 'object_values.3.value'))->toBe(0)
        ->and(data_get($sourcePayload, '_meta.source_key'))->toBe('869244041754866:00')
        ->and(data_get($sourcePayload, '_meta.peripheral_type_hex'))->toBe('00')
        ->and($result['summary'])->toMatchArray([
            'route' => 'source_peripheral_topics',
            'published_child_count' => 1,
            'published_children' => [
                '869244041754866:00',
            ],
        ]);
});
