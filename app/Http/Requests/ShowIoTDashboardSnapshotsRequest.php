<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ShowIoTDashboardSnapshotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'widget' => ['nullable', 'integer', 'min:1'],
            'widgets' => ['nullable', 'array'],
            'widgets.*' => ['integer', 'min:1'],
            'history_from_at' => ['nullable', 'date', 'required_with:history_until_at'],
            'history_until_at' => ['nullable', 'date', 'required_with:history_from_at', 'after:history_from_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'history_from_at.required_with' => 'A history start time is required when an end time is provided.',
            'history_until_at.required_with' => 'A history end time is required when a start time is provided.',
            'history_until_at.after' => 'The history end time must be after the start time.',
        ];
    }

    public function widgetId(): ?int
    {
        $widgetId = $this->integer('widget');

        return $widgetId > 0 ? $widgetId : null;
    }

    /**
     * @return array<int, int>
     */
    public function widgetIds(): array
    {
        $validatedWidgetIds = $this->validated('widgets', []);

        if (! is_array($validatedWidgetIds)) {
            return [];
        }

        return collect($validatedWidgetIds)
            ->filter(fn (mixed $value): bool => is_numeric($value) && (int) $value > 0)
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    public function historyRange(): ?DashboardHistoryRange
    {
        $fromInput = $this->validated('history_from_at');
        $untilInput = $this->validated('history_until_at');

        if (! is_string($fromInput) || ! is_string($untilInput)) {
            return null;
        }

        return new DashboardHistoryRange(
            fromAt: CarbonImmutable::parse($fromInput),
            untilAt: CarbonImmutable::parse($untilInput),
        );
    }
}
