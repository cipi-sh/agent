<?php

namespace Cipi\Agent\Jobs;

use Cipi\Agent\Anonymizer\Anonymizer;
use Cipi\Agent\Anonymizer\Config;
use Cipi\Agent\Mail\DatabaseAnonymized;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class AnonymizeDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $email,
        public string $configPath,
        public ?string $tempDir = null,
        public ?bool $gzip = null,
    ) {}

    public function handle(): void
    {
        $jobId = Str::random(32);
        $ttl = max(1, (int) config('cipi.anonymizer_ttl', 15));
        $outputPath = storage_path("cipi/anonymized_{$jobId}.sql");

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0750, true);
        }

        try {
            Log::info("Starting database anonymization job for {$this->email}", ['job_id' => $jobId, 'config' => $this->configPath]);

            $config = Config::load($this->configPath);
            $report = (new Anonymizer($config))->run($outputPath, $this->tempDir, $this->gzip, fn (string $m) => Log::debug("[anonymizer {$jobId}] {$m}"));
            $outputPath = $report['output'];

            $downloadToken = Str::random(64);
            $expiresAt = now()->addMinutes($ttl);
            $signedUrl = URL::temporarySignedRoute('cipi.db.download', $expiresAt, ['token' => $downloadToken]);

            $tokenFile = storage_path("cipi/tokens/{$downloadToken}.json");
            if (! is_dir(dirname($tokenFile))) {
                mkdir(dirname($tokenFile), 0750, true);
            }
            file_put_contents($tokenFile, json_encode([
                'file_path' => $outputPath,
                'email' => $this->email,
                'created_at' => now()->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
            ]));
            chmod($tokenFile, 0600);

            Mail::to($this->email)->send(new DatabaseAnonymized($signedUrl, $jobId, $ttl, $report));

            Log::info("Database anonymization completed for {$this->email}", [
                'job_id' => $jobId,
                'size' => $report['size'],
                'rows_transformed' => $report['rows_transformed'],
                'truncated' => $report['truncated'],
                'warnings' => $report['warnings'],
            ]);
        } catch (\Throwable $e) {
            Log::error("Database anonymization failed for {$this->email}", ['job_id' => $jobId, 'error' => $e->getMessage()]);
            $this->sendFailureNotification($e->getMessage(), $jobId);
            foreach ([$outputPath, $outputPath.'.gz'] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            throw $e;
        }
    }

    protected function sendFailureNotification(string $error, string $jobId): void
    {
        try {
            Mail::raw(
                "Database anonymization failed.\n\nError: {$error}\n\nJob ID: {$jobId}\n\nCheck the anonymization config (php artisan cipi:anonymize-audit) and try again.",
                fn ($message) => $message->to($this->email)->subject('Database Anonymization Failed')
            );
        } catch (\Throwable $e) {
            Log::error("Failed to send failure notification to {$this->email}", ['error' => $e->getMessage()]);
        }
    }
}
