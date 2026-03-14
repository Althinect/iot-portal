<?php

declare(strict_types=1);

use Dotenv\Dotenv;

it('contains a valid dotenv example file', function (): void {
    $contents = file_get_contents(base_path('.env.example'));

    expect($contents)->not->toBeFalse();

    if ($contents === false) {
        return;
    }

    $variables = Dotenv::parse($contents);

    expect($variables['INGESTION_TELEMETRY_CHUNK_INTERVAL'])->toBe('1 day')
        ->and($variables['INGESTION_TELEMETRY_COMPRESS_AFTER'])->toBe('7 days')
        ->and($variables['INGESTION_TELEMETRY_RETENTION'])->toBe('90 days');
});

it('documents the custom platform environment variables in the example file', function (): void {
    $customConfigVariables = [
        ...environmentVariablesFromConfigFile(config_path('iot.php')),
        ...environmentVariablesFromConfigFile(config_path('ingestion.php')),
        ...environmentVariablesFromConfigFile(config_path('automation.php')),
        ...environmentVariablesFromConfigFile(config_path('horizon.php')),
        ...environmentVariablesFromConfigFile(config_path('reporting.php')),
        'DEVICE_CONTROL_LOG_LEVEL',
        'AUTOMATION_LOG_LEVEL',
        'REDIS_SIMULATIONS_QUEUE_CONNECTION',
        'REDIS_SIMULATIONS_QUEUE',
        'REDIS_SIMULATIONS_QUEUE_RETRY_AFTER',
    ];

    $documentedVariables = [];

    foreach (file(base_path('.env.example')) ?: [] as $line) {
        if (preg_match('/^\s*#?\s*([A-Z0-9_]+)=/', $line, $matches) === 1) {
            $documentedVariables[$matches[1]] = true;
        }
    }

    $missingVariables = array_values(array_diff(array_unique($customConfigVariables), array_keys($documentedVariables)));
    sort($missingVariables);

    expect($missingVariables)
        ->toBeEmpty("Missing custom config variables from .env.example:\n- ".implode("\n- ", $missingVariables));
});

function environmentVariablesFromConfigFile(string $path): array
{
    $variables = [];
    $tokens = token_get_all(file_get_contents($path));
    $tokenCount = count($tokens);

    for ($index = 0; $index < $tokenCount; $index++) {
        $token = $tokens[$index];

        if (! is_array($token) || strtolower($token[1]) !== 'env') {
            continue;
        }

        $openingParenthesisIndex = nextMeaningfulTokenIndex($tokens, $index + 1);

        if (($tokens[$openingParenthesisIndex] ?? null) !== '(') {
            continue;
        }

        $argumentIndex = nextMeaningfulTokenIndex($tokens, $openingParenthesisIndex + 1);
        $argumentToken = $tokens[$argumentIndex] ?? null;

        if (! is_array($argumentToken) || $argumentToken[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $variables[] = trim($argumentToken[1], "'\"");
    }

    return array_values(array_unique($variables));
}

function nextMeaningfulTokenIndex(array $tokens, int $startIndex): int
{
    $tokenCount = count($tokens);

    for ($index = $startIndex; $index < $tokenCount; $index++) {
        $token = $tokens[$index];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $index;
    }

    return $tokenCount;
}
