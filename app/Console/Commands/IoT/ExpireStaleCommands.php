<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Events\CommandTimedOut;
use Illuminate\Console\Command;

class ExpireStaleCommands extends Command
{
    protected $signature = 'iot:expire-stale-commands
                            {--seconds= : Timeout threshold in seconds}';

    protected $description = 'Mark stale command logs as timeout and broadcast timeout events';

    public function handle(): int
    {
        $secondsOption = $this->option('seconds');
        $configuredTimeout = config('iot.device_control.command_timeout_seconds', 120);
        $fallbackTimeout = is_numeric($configuredTimeout) ? (int) $configuredTimeout : 120;
        $seconds = is_numeric($secondsOption) ? (int) $secondsOption : $fallbackTimeout;

        $timeoutCutoff = now()->subSeconds(max(1, $seconds));
        $timedOutCount = 0;

        DeviceCommandLog::query()
            ->whereIn('status', [
                CommandStatus::Pending->value,
                CommandStatus::Sent->value,
                CommandStatus::Acknowledged->value,
            ])
            ->where(function ($query) use ($timeoutCutoff): void {
                $query->where(function ($inner) use ($timeoutCutoff): void {
                    $inner->whereNotNull('sent_at')->where('sent_at', '<=', $timeoutCutoff);
                })->orWhere(function ($inner) use ($timeoutCutoff): void {
                    $inner->whereNull('sent_at')->where('created_at', '<=', $timeoutCutoff);
                });
            })
            ->orderBy('id')
            ->chunkById(100, function ($commandLogs) use (&$timedOutCount): void {
                foreach ($commandLogs as $commandLog) {
                    $commandLog->update([
                        'status' => CommandStatus::Timeout,
                        'error_message' => $commandLog->error_message ?? 'Command timed out waiting for device feedback.',
                    ]);

                    $commandLog->refresh();
                    $commandLog->loadMissing('device', 'channel');

                    event(new CommandTimedOut($commandLog));
                    $timedOutCount++;
                }
            });

        $this->info("Timed out {$timedOutCount} command(s) older than {$timeoutCutoff->toIso8601String()}.");

        return self::SUCCESS;
    }
}
