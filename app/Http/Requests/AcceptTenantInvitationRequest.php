<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Shared\Models\TenantInvitation;
use App\Domain\Shared\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AcceptTenantInvitationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $invitation = $this->route('invitation');
        $requiresAccount = $invitation instanceof TenantInvitation
            && ! User::query()->where('email', $invitation->email)->exists();

        return [
            'name' => [Rule::requiredIf($requiresAccount), 'nullable', 'string', 'max:255'],
            'password' => [
                Rule::requiredIf($requiresAccount),
                'nullable',
                'confirmed',
                Password::defaults(),
            ],
        ];
    }
}
