<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use Illuminate\Console\Command;
use RuntimeException;

class InitializeDevicePkiCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'iot:pki:init
                            {--days=3650 : Validity period for the root CA certificate}
                            {--force : Overwrite existing root CA material}';

    /**
     * @var string
     */
    protected $description = 'Initialize local X.509 root CA files used for device certificate provisioning';

    public function handle(): int
    {
        $certificatePath = $this->caCertificatePath();
        $privateKeyPath = $this->caPrivateKeyPath();
        $shouldForce = (bool) $this->option('force');
        $days = $this->resolveValidityDays();

        if (! $shouldForce && is_file($certificatePath) && is_file($privateKeyPath)) {
            $this->info("PKI material already exists:\n - {$certificatePath}\n - {$privateKeyPath}");
            $this->line('Use --force to regenerate.');

            return self::SUCCESS;
        }

        $this->ensureDirectoryExists(dirname($certificatePath));
        $this->ensureDirectoryExists(dirname($privateKeyPath));

        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 4096,
        ]);

        if ($privateKey === false) {
            throw new RuntimeException('Failed to generate CA private key.');
        }

        $subject = [
            'countryName' => $this->subjectCountry(),
            'organizationName' => $this->subjectOrganization(),
            'organizationalUnitName' => 'IoT Platform PKI',
            'commonName' => 'IoT Device Root CA',
        ];

        $csr = openssl_csr_new($subject, $privateKey, ['digest_alg' => 'sha256']);

        if ($csr === false || $csr === true) {
            throw new RuntimeException('Failed to generate CA CSR.');
        }

        $signedCertificate = openssl_csr_sign(
            $csr,
            null,
            $privateKey,
            $days,
            ['digest_alg' => 'sha256']
        );

        if ($signedCertificate === false) {
            throw new RuntimeException('Failed to self-sign CA certificate.');
        }

        $privateKeyPem = '';
        $privateKeyExported = openssl_pkey_export($privateKey, $privateKeyPem);

        if (! $privateKeyExported || trim($privateKeyPem) === '') {
            throw new RuntimeException('Failed to export CA private key.');
        }

        $certificatePem = '';
        $certificateExported = openssl_x509_export($signedCertificate, $certificatePem);

        if (! $certificateExported || trim($certificatePem) === '') {
            throw new RuntimeException('Failed to export CA certificate.');
        }

        file_put_contents($privateKeyPath, $privateKeyPem);
        file_put_contents($certificatePath, $certificatePem);
        @chmod($privateKeyPath, 0600);

        $this->info('Local PKI initialized successfully.');
        $this->line("CA certificate: {$certificatePath}");
        $this->line("CA private key: {$privateKeyPath}");

        return self::SUCCESS;
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

    private function resolveValidityDays(): int
    {
        $option = $this->option('days');

        if (is_numeric($option)) {
            $days = (int) $option;

            if ($days >= 1 && $days <= 36500) {
                return $days;
            }
        }

        return 3650;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }
    }

    private function subjectCountry(): string
    {
        $value = config('iot.pki.subject.country', 'US');

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : 'US';
    }

    private function subjectOrganization(): string
    {
        $value = config('iot.pki.subject.organization', config('app.name', 'IoT Portal'));

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : 'IoT Portal';
    }
}
