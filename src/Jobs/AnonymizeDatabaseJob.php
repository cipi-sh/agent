<?php

namespace Cipi\Agent\Jobs;

use Cipi\Agent\Mail\DatabaseAnonymized;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class AnonymizeDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hour timeout
    public int $tries = 1;

    public function __construct(
        public string $email,
        public string $configPath,
        public ?string $tempDir = null
    ) {}

    public function handle(): void
    {
        $appUser = config('cipi.app_user');
        $jobId = Str::random(32);
        $outputPath = storage_path("cipi/anonymized_{$jobId}.sql");

        // Ensure output directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        try {
            Log::info("Starting database anonymization job for {$this->email}", [
                'job_id' => $jobId,
                'config' => $this->configPath,
                'app_user' => $appUser,
            ]);

            // Run the anonymization command
            $exitCode = Artisan::call('cipi:anonymize', [
                'config' => $this->configPath,
                'output' => $outputPath,
                '--temp-dir' => $this->tempDir ?: sys_get_temp_dir() . "/cipi-job-{$jobId}",
            ]);

            if ($exitCode !== 0) {
                throw new \Exception('Anonymization command failed with exit code: ' . $exitCode);
            }

            // Generate signed download URL (valid for 15 minutes)
            $downloadToken = Str::random(64);
            $signedUrl = URL::temporarySignedRoute(
                'cipi.db.download',
                now()->addMinutes(15),
                ['token' => $downloadToken]
            );

            // Store the mapping between token and file path
            $tokenFile = storage_path("cipi/tokens/{$downloadToken}.json");
            $tokenDir = dirname($tokenFile);
            if (!is_dir($tokenDir)) {
                mkdir($tokenDir, 0755, true);
            }

            file_put_contents($tokenFile, json_encode([
                'file_path' => $outputPath,
                'email' => $this->email,
                'created_at' => now()->toIso8601String(),
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ]));

            // Send notification email
            Mail::to($this->email)->send(new DatabaseAnonymized($signedUrl, $jobId));

            Log::info("Database anonymization completed successfully for {$this->email}", [
                'job_id' => $jobId,
                'file_size' => filesize($outputPath),
            ]);

        } catch (\Throwable $e) {
            Log::error("Database anonymization failed for {$this->email}", [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send failure notification
            $this->sendFailureNotification($e->getMessage(), $jobId);

            // Cleanup on failure
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            throw $e;
        }
    }

    protected function sendFailureNotification(string $error, string $jobId): void
    {
        try {
            Mail::raw(
                "Database anonymization failed.\n\nError: {$error}\n\nJob ID: {$jobId}\n\nPlease check your configuration and try again.",
                function ($message) {
                    $message->to($this->email)
                            ->subject('Database Anonymization Failed');
                }
            );
        } catch (\Throwable $e) {
            Log::error("Failed to send failure notification to {$this->email}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}