<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceSchema\Models;

use App\Domain\DeviceSchema\Enums\MetricUnit;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParameterDefinition>
 */
class ParameterDefinitionFactory extends Factory
{
    protected $model = ParameterDefinition::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dataType = $this->faker->randomElement(ParameterDataType::cases());
        $category = $this->faker->randomElement(ParameterCategory::cases());

        return [
            'schema_version_topic_id' => SchemaVersionTopic::factory(),
            'key' => $this->faker->unique()->slug(2),
            'label' => $this->faker->words(2, true),
            'json_path' => $this->faker->randomElement(['temp', 'status.temp', '$.status.temp']),
            'type' => $dataType,
            'category' => $category,
            'unit' => $dataType === ParameterDataType::Decimal
                ? $this->faker->randomElement([
                    MetricUnit::Celsius->value,
                    MetricUnit::Percent->value,
                    MetricUnit::Volts->value,
                    MetricUnit::Amperes->value,
                    MetricUnit::KilowattHours->value,
                    MetricUnit::Watts->value,
                ])
                : null,
            'required' => $this->faker->boolean(60),
            'is_critical' => $this->faker->boolean(20),
            'validation_rules' => [
                'min' => -40,
                'max' => 85,
            ],
            'control_ui' => null,
            'validation_error_code' => $this->faker->optional()->lexify('VAL_????'),
            'mutation_expression' => [
                '*' => [
                    ['var' => 'val'],
                    1.0,
                ],
            ],
            'sequence' => $this->faker->numberBetween(1, 10),
            'is_active' => $this->faker->boolean(90),
        ];
    }

    /**
     * State for subscribe (command) parameters — omits telemetry-only fields
     * and sets a sensible default_value based on the data type.
     */
    public function subscribe(): static
    {
        return $this->state(fn () => [
            'schema_version_topic_id' => SchemaVersionTopic::factory()->subscribe(),
            'json_path' => $this->faker->unique()->slug(1),
            'is_critical' => false,
            'validation_error_code' => null,
            'mutation_expression' => null,
            'default_value' => 0,
            'category' => ParameterCategory::Measurement,
            'control_ui' => null,
        ]);
    }
}
