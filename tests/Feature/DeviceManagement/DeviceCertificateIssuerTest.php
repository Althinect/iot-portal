<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceCertificate;
use App\Domain\DeviceManagement\Services\DeviceCertificateIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function configureTestPkiPaths(): array
{
    $directory = storage_path('framework/testing/pki-'.Str::uuid()->toString());

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $certificatePath = "{$directory}/ca.crt";
    $privateKeyPath = "{$directory}/ca.key";

    $privateKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);
    $csr = openssl_csr_new([
        'countryName' => 'US',
        'organizationName' => 'IoT Portal Tests',
        'commonName' => 'Local Test Root CA',
    ], $privateKey, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $privateKey, 3650, ['digest_alg' => 'sha256']);

    $privateKeyPem = '';
    $certificatePem = '';
    openssl_pkey_export($privateKey, $privateKeyPem);
    openssl_x509_export($certificate, $certificatePem);

    file_put_contents($privateKeyPath, $privateKeyPem);
    file_put_contents($certificatePath, $certificatePem);

    config([
        'iot.pki.ca_certificate_path' => $certificatePath,
        'iot.pki.ca_private_key_path' => $privateKeyPath,
        'iot.pki.default_validity_days' => 365,
    ]);

    return [$certificatePath, $privateKeyPath];
}

it('issues a device certificate and sets it as active', function (): void {
    configureTestPkiPaths();

    $device = Device::factory()->create();

    /** @var DeviceCertificateIssuer $issuer */
    $issuer = app(DeviceCertificateIssuer::class);
    $certificate = $issuer->issueForDevice($device, 365);

    expect($certificate)
        ->toBeInstanceOf(DeviceCertificate::class)
        ->and($certificate->device_id)->toBe($device->id)
        ->and($certificate->serial_number)->not->toBe('')
        ->and($certificate->fingerprint_sha256)->not->toBe('')
        ->and($certificate->decryptedPrivateKey())->not->toBeNull()
        ->and($certificate->isActive())->toBeTrue();

    $activeCertificate = $device->fresh()->activeCertificate()->first();

    expect($activeCertificate)->not->toBeNull()
        ->and($activeCertificate?->id)->toBe($certificate->id);
});

it('rotates device certificates by revoking the previous active certificate', function (): void {
    configureTestPkiPaths();

    $device = Device::factory()->create();

    /** @var DeviceCertificateIssuer $issuer */
    $issuer = app(DeviceCertificateIssuer::class);

    $firstCertificate = $issuer->issueForDevice($device, 365);
    $secondCertificate = $issuer->rotateForDevice($device, 365);

    $firstCertificate->refresh();
    $device->refresh();

    expect($firstCertificate->revoked_at)->not->toBeNull()
        ->and($firstCertificate->revocation_reason)->toBe('superseded')
        ->and($secondCertificate->id)->not->toBe($firstCertificate->id)
        ->and($device->activeCertificate()->value('id'))->toBe($secondCertificate->id);
});

it('revokes active device certificates on demand', function (): void {
    configureTestPkiPaths();

    $device = Device::factory()->create();

    /** @var DeviceCertificateIssuer $issuer */
    $issuer = app(DeviceCertificateIssuer::class);
    $issuer->issueForDevice($device, 365);

    $revokedCount = $issuer->revokeActiveForDevice($device, 'manual_test_revocation');

    expect($revokedCount)->toBe(1)
        ->and($device->fresh()->activeCertificate()->exists())->toBeFalse();
});

it('builds a provisioning bundle with ca and active certificate material', function (): void {
    [$certificatePath] = configureTestPkiPaths();

    $device = Device::factory()->create();

    /** @var DeviceCertificateIssuer $issuer */
    $issuer = app(DeviceCertificateIssuer::class);
    $issuer->issueForDevice($device, 365);

    $bundle = $issuer->buildProvisioningBundle($device->fresh());
    $expectedCaContent = file_get_contents($certificatePath);

    expect($bundle['has_active_certificate'])->toBeTrue()
        ->and($bundle['ca_certificate_pem'])->toBe($expectedCaContent)
        ->and($bundle['device_certificate_pem'])->toContain('BEGIN CERTIFICATE')
        ->and($bundle['device_private_key_pem'])->toContain('BEGIN PRIVATE KEY');
});

it('keeps the previous certificate active when rotation fails before storing a replacement', function (): void {
    configureTestPkiPaths();

    $device = Device::factory()->create();

    /** @var DeviceCertificateIssuer $issuer */
    $issuer = app(DeviceCertificateIssuer::class);
    $firstCertificate = $issuer->issueForDevice($device, 365);

    config([
        'iot.pki.ca_private_key_path' => storage_path('framework/testing/does-not-exist-ca.key'),
    ]);

    expect(fn (): DeviceCertificate => $issuer->rotateForDevice($device, 365))
        ->toThrow(RuntimeException::class);

    $firstCertificate->refresh();
    $activeCertificate = $device->fresh()->activeCertificate()->first();

    expect($firstCertificate->revoked_at)->toBeNull()
        ->and($activeCertificate)->not->toBeNull()
        ->and($activeCertificate?->id)->toBe($firstCertificate->id);
});
