<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceSchema\Models;

use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Enums\TopicPurpose;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SchemaVersionTopic>
 */
class SchemaVersionTopicFactory extends Factory
{
    protected $model = SchemaVersionTopic::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'topic_'.bin2hex(random_bytes(2));
        $direction = random_int(0, 1) === 0 ? TopicDirection::Publish : TopicDirection::Subscribe;
        $publishPurposes = [TopicPurpose::State, TopicPurpose::Telemetry, TopicPurpose::Ack];

        return [
            'device_schema_version_id' => DeviceSchemaVersion::factory(),
            'key' => $key,
            'label' => Str::title(str_replace('_', ' ', $key)),
            'direction' => $direction,
            'purpose' => $direction === TopicDirection::Subscribe
                ? TopicPurpose::Command
                : $publishPurposes[array_rand($publishPurposes)],
            'suffix' => $key,
            'description' => null,
            'qos' => random_int(0, 2),
            'retain' => random_int(1, 100) <= 20,
            'sequence' => random_int(0, 10),
        ];
    }

    public function publish(): static
    {
        return $this->state(fn () => [
            'direction' => TopicDirection::Publish,
            'purpose' => TopicPurpose::Telemetry,
        ]);
    }

    public function subscribe(): static
    {
        return $this->state(fn () => [
            'direction' => TopicDirection::Subscribe,
            'purpose' => TopicPurpose::Command,
        ]);
    }

    public function stateTopic(): static
    {
        return $this->state(fn (): array => [
            'direction' => TopicDirection::Publish,
            'purpose' => TopicPurpose::State,
            'retain' => true,
        ]);
    }

    public function ack(): static
    {
        return $this->state(fn (): array => [
            'direction' => TopicDirection::Publish,
            'purpose' => TopicPurpose::Ack,
            'suffix' => 'ack',
        ]);
    }
}
