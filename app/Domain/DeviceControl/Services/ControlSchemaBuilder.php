<?php

declare(strict_types=1);

namespace App\Domain\DeviceControl\Services;

use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;

class ControlSchemaBuilder
{
    /**
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     json_path: string,
     *     widget: string,
     *     type: string,
     *     required: bool,
     *     default: mixed,
     *     min: int|float|null,
     *     max: int|float|null,
     *     step: int|float,
     *     options: array<int|string, string>,
     *     unit: string|null,
     *     button_value: mixed
     * }>
     */
    public function buildForChannel(DeviceChannel $channel): array
    {
        $channel->loadMissing('parameters');

        return $channel->parameters
            ->where('is_active', true)
            ->sortBy('sequence')
            ->values()
            ->map(fn (ProfileParameterDefinition $parameter): array => $this->buildControlDefinition($parameter))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultControlValues(DeviceChannel $channel): array
    {
        $channel->loadMissing('parameters');

        return $channel->parameters
            ->where('is_active', true)
            ->sortBy('sequence')
            ->mapWithKeys(function (ProfileParameterDefinition $parameter): array {
                return [
                    $parameter->key => $parameter->resolvedDefaultValue(),
                ];
            })
            ->all();
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     json_path: string,
     *     widget: string,
     *     type: string,
     *     required: bool,
     *     default: mixed,
     *     min: int|float|null,
     *     max: int|float|null,
     *     step: int|float,
     *     options: array<int|string, string>,
     *     unit: string|null,
     *     button_value: mixed
     * }
     */
    private function buildControlDefinition(ProfileParameterDefinition $parameter): array
    {
        $range = $parameter->resolvedNumericRange();

        return [
            'key' => $parameter->key,
            'label' => $parameter->label,
            'json_path' => $parameter->json_path,
            'widget' => $parameter->resolvedWidgetType()->value,
            'type' => $parameter->type->value,
            'required' => (bool) $parameter->required,
            'default' => $parameter->resolvedDefaultValue(),
            'min' => $range['min'],
            'max' => $range['max'],
            'step' => $range['step'],
            'options' => $parameter->resolvedSelectOptions(),
            'unit' => $parameter->unit,
            'button_value' => $parameter->resolvedButtonValue(),
        ];
    }
}
