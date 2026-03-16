<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceSchema\Models;

use App\Domain\DeviceSchema\Enums\TopicLinkType;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\DeviceSchema\Models\SchemaVersionTopicLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchemaVersionTopicLink>
 */
class SchemaVersionTopicLinkFactory extends Factory
{
    protected $model = SchemaVersionTopicLink::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_schema_version_topic_id' => SchemaVersionTopic::factory()->subscribe(),
            'to_schema_version_topic_id' => SchemaVersionTopic::factory()->publish(),
            'link_type' => $this->faker->randomElement(TopicLinkType::cases()),
        ];
    }
}
