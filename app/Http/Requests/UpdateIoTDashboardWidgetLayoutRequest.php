<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIoTDashboardWidgetLayoutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var IoTDashboard|null $dashboard */
        $dashboard = $this->route('dashboard');
        /** @var IoTDashboardWidget|null $widget */
        $widget = $this->route('widget');

        if (! $dashboard instanceof IoTDashboard || ! $widget instanceof IoTDashboardWidget) {
            return false;
        }

        if ((int) $widget->iot_dashboard_id !== (int) $dashboard->id) {
            return false;
        }

        return $this->user()?->can('update', $widget) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'x' => ['required', 'integer', 'min:0', 'max:64'],
            'y' => ['required', 'integer', 'min:0', 'max:256'],
            'w' => ['required', 'integer', 'min:1', 'max:24'],
            'h' => ['required', 'integer', 'min:2', 'max:12'],
        ];
    }
}
