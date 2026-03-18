<?php

declare(strict_types=1);

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use Database\Seeders\WitcoMigrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('expands a source topic payload into bound physical device envelopes', function (): void {
    $this->seed(WitcoMigrationSeeder::class);

    /** @var DeviceSignalBindingResolver $resolver */
    $resolver = app(DeviceSignalBindingResolver::class);

    $sourceTopic = 'migration/source/imoni/869244041754866/00/telemetry';
    $expandedEnvelopes = $resolver->expand(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'peripheral_name' => 'iMoni_LITE',
            'peripheral_type_hex' => '00',
            'io_2_value' => 1,
            'io_3_value' => 0,
        ],
        receivedAt: now(),
    ));

    $payloadsByDevice = $expandedEnvelopes
        ->mapWithKeys(fn (IncomingTelemetryEnvelope $envelope): array => [
            (string) $envelope->deviceExternalId => [
                'mqtt_topic' => $envelope->mqttTopic,
                'payload' => $envelope->payload,
            ],
        ]);

    expect($resolver->supportsTopic($sourceTopic))->toBeTrue()
        ->and($expandedEnvelopes)->toHaveCount(2)
        ->and($payloadsByDevice->keys()->sort()->values()->all())->toBe([
            '869244041754866-00-02',
            '869244041754866-00-03',
        ])
        ->and($payloadsByDevice->get('869244041754866-00-02'))->toMatchArray([
            'mqtt_topic' => 'devices/imoni-status/869244041754866-00-02/telemetry',
            'payload' => [
                'status' => 1,
                '_meta' => [
                    'binding_mode' => 'device_signal',
                    'source_adapter' => 'imoni',
                    'source_topic' => $sourceTopic,
                    'source_subject' => str_replace('/', '.', $sourceTopic),
                ],
            ],
        ])
        ->and($payloadsByDevice->get('869244041754866-00-03'))->toMatchArray([
            'mqtt_topic' => 'devices/imoni-status/869244041754866-00-03/telemetry',
            'payload' => [
                'status' => 0,
                '_meta' => [
                    'binding_mode' => 'device_signal',
                    'source_adapter' => 'imoni',
                    'source_topic' => $sourceTopic,
                    'source_subject' => str_replace('/', '.', $sourceTopic),
                ],
            ],
        ]);
});
