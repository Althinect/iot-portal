<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceManagement\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceCertificate;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceCertificate>
 */
class DeviceCertificateFactory extends Factory
{
    protected $model = DeviceCertificate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issuedAt = now();
        $notBefore = $issuedAt->copy()->subMinute();
        $notAfter = $issuedAt->copy()->addYear();

        return [
            'device_id' => Device::factory(),
            'issued_by_user_id' => User::factory(),
            'serial_number' => strtoupper(Str::random(24)),
            'subject_dn' => 'CN=device-'.Str::uuid()->toString(),
            'fingerprint_sha256' => hash('sha256', Str::uuid()->toString()),
            'certificate_pem' => "-----BEGIN CERTIFICATE-----\n".base64_encode(Str::random(96))."\n-----END CERTIFICATE-----",
            'private_key_encrypted' => Crypt::encryptString(
                "-----BEGIN PRIVATE KEY-----\n".base64_encode(Str::random(128))."\n-----END PRIVATE KEY-----"
            ),
            'issued_at' => $issuedAt,
            'not_before' => $notBefore,
            'not_after' => $notAfter,
            'revoked_at' => null,
            'revocation_reason' => null,
        ];
    }

    public function revoked(string $reason = 'revoked'): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => null,
            'revocation_reason' => null,
            'not_after' => now()->addYear(),
        ]);
    }
}
