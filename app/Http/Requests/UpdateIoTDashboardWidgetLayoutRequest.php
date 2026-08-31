<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIoTDashboardWidgetLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dashboard = $this->route('dashboard');
        $widget = $this->route('widget');
        $user = $this->user();

        return $dashboard instanceof IoTDashboard
            && $widget instanceof IoTDashboardWidget
            && (int) $widget->iot_dashboard_id === (int) $dashboard->id
            && $user !== null
            && $user->can('view', $dashboard)
            && $user->can('layout', $widget);
    }

    /**
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
