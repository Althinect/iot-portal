<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Services;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeviceCertificateIssuer
{
    public function issueForDevice(
        Device $device,
        ?int $validityDays = null,
        ?int $issuedByUserId = null,
        bool $revokeExisting = true,
    ): DeviceCertificate {
        return DB::transaction(function () use ($device, $validityDays, $issuedByUserId, $revokeExisting): DeviceCertificate {
            $resolvedValidityDays = $this->resolveValidityDays($validityDays);
            [$certificatePem, $privateKeyPem, $parsedCertificate] = $this->generateSignedCertificate($device, $resolvedValidityDays);

            $fingerprint = openssl_x509_fingerprint($certificatePem, 'sha256');

            if (! is_string($fingerprint) || trim($fingerprint) === '') {
                throw new RuntimeException('Failed to compute certificate fingerprint.');
            }

            $issuedAt = CarbonImmutable::now();
            $notBeforeValue = $parsedCertificate['validFrom_time_t'] ?? null;
            $notAfterValue = $parsedCertificate['validTo_time_t'] ?? null;
            $notBeforeTimestamp = is_numeric($notBeforeValue) ? (int) $notBeforeValue : $issuedAt->getTimestamp();
            $notAfterTimestamp = is_numeric($notAfterValue)
                ? (int) $notAfterValue
                : $issuedAt->addDays($resolvedValidityDays)->getTimestamp();
            $serialNumber = $this->resolveSerialNumber($parsedCertificate);

            $newCertificate = $device->certificates()->create([
                'issued_by_user_id' => $issuedByUserId,
                'serial_number' => $serialNumber,
                'subject_dn' => $this->resolveSubjectDn($parsedCertificate, $device),
                'fingerprint_sha256' => strtolower($fingerprint),
                'certificate_pem' => $certificatePem,
                'private_key_encrypted' => Crypt::encryptString($privateKeyPem),
                'issued_at' => $issuedAt,
                'not_before' => CarbonImmutable::createFromTimestampUTC($notBeforeTimestamp),
                'not_after' => CarbonImmutable::createFromTimestampUTC($notAfterTimestamp),
                'revoked_at' => null,
                'revocation_reason' => null,
            ]);

            if ($revokeExisting) {
                $device->certificates()
                    ->whereKeyNot($newCertificate->getKey())
                    ->whereNull('revoked_at')
                    ->where('not_after', '>', now())
                    ->update([
                        'revoked_at' => now(),
                        'revocation_reason' => 'superseded',
                        'updated_at' => now(),
                    ]);
            }

            return $newCertificate;
        });
    }

    public function rotateForDevice(Device $device, ?int $validityDays = null, ?int $issuedByUserId = null): DeviceCertificate
    {
        return $this->issueForDevice(
            device: $device,
            validityDays: $validityDays,
            issuedByUserId: $issuedByUserId,
            revokeExisting: true,
        );
    }

    public function revokeCertificate(DeviceCertificate $certificate, string $reason = 'revoked'): DeviceCertificate
    {
        $certificate->update([
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);

        return $certificate->fresh() ?? $certificate;
    }

    public function revokeActiveForDevice(Device $device, string $reason = 'revoked'): int
    {
        return $device->certificates()
            ->whereNull('revoked_at')
            ->where('not_after', '>', now())
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{
     *     ca_certificate_pem: string,
     *     device_certificate_pem: string,
     *     device_private_key_pem: string,
     *     has_active_certificate: bool
     * }
     */
    public function buildProvisioningBundle(Device $device): array
    {
        $device->loadMissing('activeCertificate');

        $certificate = $device->activeCertificate;

        return [
            'ca_certificate_pem' => $this->readOptionalFile($this->caCertificatePath()),
            'device_certificate_pem' => is_string($certificate?->certificate_pem) ? $certificate->certificate_pem : '',
            'device_private_key_pem' => $certificate?->decryptedPrivateKey() ?? '',
            'has_active_certificate' => $certificate?->isActive() ?? false,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function generateSignedCertificate(Device $device, int $validityDays): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($privateKey === false) {
            throw new RuntimeException('Failed to create private key for device certificate.');
        }

        $subject = $this->buildSubject($device);

        $csr = openssl_csr_new($subject, $privateKey, ['digest_alg' => 'sha256']);

        if ($csr === false || $csr === true) {
            throw new RuntimeException('Failed to generate CSR for device certificate.');
        }

        $certificateAuthorityCertificate = $this->readRequiredFile($this->caCertificatePath());
        $certificateAuthorityPrivateKeyPem = $this->readRequiredFile($this->caPrivateKeyPath());

        $certificateAuthorityPrivateKey = openssl_pkey_get_private($certificateAuthorityPrivateKeyPem);

        if ($certificateAuthorityPrivateKey === false) {
            throw new RuntimeException('Failed to load certificate authority private key.');
        }

        $serial = random_int(1, 2_147_483_647);

        $signedCertificate = openssl_csr_sign(
            $csr,
            $certificateAuthorityCertificate,
            $certificateAuthorityPrivateKey,
            $validityDays,
            ['digest_alg' => 'sha256'],
            $serial
        );

        if ($signedCertificate === false) {
            throw new RuntimeException('Failed to sign device certificate with certificate authority.');
        }

        $certificatePem = '';
        $certificateExported = openssl_x509_export($signedCertificate, $certificatePem);

        if (! $certificateExported || trim($certificatePem) === '') {
            throw new RuntimeException('Failed to export signed device certificate.');
        }

        $privateKeyPem = '';
        $privateKeyExported = openssl_pkey_export($privateKey, $privateKeyPem);

        if (! $privateKeyExported || trim($privateKeyPem) === '') {
            throw new RuntimeException('Failed to export generated device private key.');
        }

        $parsedCertificate = openssl_x509_parse($certificatePem);

        if (! is_array($parsedCertificate)) {
            throw new RuntimeException('Failed to parse generated device certificate.');
        }

        return [$certificatePem, $privateKeyPem, $parsedCertificate];
    }

    /**
     * @return array<string, string>
     */
    private function buildSubject(Device $device): array
    {
        $device->loadMissing('organization');

        $organizationNameValue = config('iot.pki.subject.organization', config('app.name', 'IoT Portal'));
        $countryNameValue = config('iot.pki.subject.country', 'US');
        $organizationName = is_string($organizationNameValue) && trim($organizationNameValue) !== ''
            ? trim($organizationNameValue)
            : 'IoT Portal';
        $countryName = is_string($countryNameValue) && trim($countryNameValue) !== ''
            ? trim($countryNameValue)
            : 'US';

        $deviceIdentifier = is_string($device->external_id) && trim($device->external_id) !== ''
            ? trim($device->external_id)
            : $device->uuid;
        $organizationUnitName = $device->organization?->name ?: 'devices';

        return [
            'countryName' => $countryName,
            'organizationName' => $organizationName,
            'organizationalUnitName' => $organizationUnitName,
            'commonName' => $deviceIdentifier,
        ];
    }

    private function caCertificatePath(): string
    {
        $configuredPath = config('iot.pki.ca_certificate_path', storage_path('app/private/iot-pki/ca.crt'));

        return is_string($configuredPath) && trim($configuredPath) !== ''
            ? trim($configuredPath)
            : storage_path('app/private/iot-pki/ca.crt');
    }

    private function caPrivateKeyPath(): string
    {
        $configuredPath = config('iot.pki.ca_private_key_path', storage_path('app/private/iot-pki/ca.key'));

        return is_string($configuredPath) && trim($configuredPath) !== ''
            ? trim($configuredPath)
            : storage_path('app/private/iot-pki/ca.key');
    }

    private function readRequiredFile(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("Required PKI file not found at: {$path}");
        }

        $content = file_get_contents($path);

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException("Required PKI file is empty or unreadable: {$path}");
        }

        return $content;
    }

    private function readOptionalFile(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        $content = file_get_contents($path);

        if (! is_string($content)) {
            return '';
        }

        return $content;
    }

    private function resolveValidityDays(?int $validityDays): int
    {
        if (is_int($validityDays) && $validityDays >= 1 && $validityDays <= 3650) {
            return $validityDays;
        }

        $configuredDays = config('iot.pki.default_validity_days', 365);

        if (is_numeric($configuredDays)) {
            $parsedDays = (int) $configuredDays;

            if ($parsedDays >= 1 && $parsedDays <= 3650) {
                return $parsedDays;
            }
        }

        return 365;
    }

    /**
     * @param  array<string, mixed>  $parsedCertificate
     */
    private function resolveSerialNumber(array $parsedCertificate): string
    {
        $serialHex = $parsedCertificate['serialNumberHex'] ?? null;

        if (is_string($serialHex) && trim($serialHex) !== '') {
            return strtoupper(trim($serialHex));
        }

        $serial = $parsedCertificate['serialNumber'] ?? null;

        if (is_string($serial) && trim($serial) !== '') {
            return trim($serial);
        }

        return strtoupper(bin2hex(random_bytes(8)));
    }

    /**
     * @param  array<string, mixed>  $parsedCertificate
     */
    private function resolveSubjectDn(array $parsedCertificate, Device $device): string
    {
        $subjectName = $parsedCertificate['name'] ?? null;

        if (is_string($subjectName) && trim($subjectName) !== '') {
            return trim($subjectName);
        }

        $deviceIdentifier = is_string($device->external_id) && trim($device->external_id) !== ''
            ? trim($device->external_id)
            : $device->uuid;

        return "CN={$deviceIdentifier}";
    }
}
