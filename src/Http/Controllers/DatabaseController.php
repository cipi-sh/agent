<?php

namespace Cipi\Agent\Http\Controllers;

use Cipi\Agent\Jobs\AnonymizeDatabaseJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class DatabaseController extends Controller
{
    public function startAnonymization(Request $request): JsonResponse
    {


        // Validate request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 422);
        }

        $email = $request->input('email');
        $appUser = config('cipi.app_user');

        if (empty($appUser)) {
            return response()->json(['error' => 'App user not configured'], 500);
        }

        // Find anonymization config file — must live on the server, outside the project repo
        $configPaths = [
            "/home/{$appUser}/.db/anonymization.json",
            "/home/{$appUser}/.cipi/anonymization.json",
        ];

        $configPath = null;
        foreach ($configPaths as $path) {
            if (file_exists($path)) {
                $configPath = $path;
                break;
            }
        }

        if (!$configPath) {
            return response()->json([
                'error' => 'Configuration file not found',
                'message' => 'Place your anonymization.json config file on the server at one of: ' .
                    implode(', ', $configPaths),
            ], 404);
        }

        // Validate config file
        $config = json_decode(file_get_contents($configPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'error' => 'Invalid configuration file',
                'message' => 'The anonymization.json file contains invalid JSON'
            ], 400);
        }

        if (empty($config['transformations'])) {
            return response()->json([
                'error' => 'Invalid configuration',
                'message' => 'No transformations defined in configuration file'
            ], 400);
        }

        // Dispatch the job
        AnonymizeDatabaseJob::dispatch($email, $configPath);

        return response()->json([
            'status' => 'queued',
            'message' => 'Database anonymization job has been queued. You will receive an email with download instructions when complete.',
            'email' => $email,
        ]);
    }

    public function downloadAnonymized(Request $request, string $token): Response
    {
        // Only enable if token is configured
        if (!config('cipi.anonymizer_enabled')) {
            abort(403, 'Database anonymizer not configured');
        }

        // Find token file
        $tokenFile = storage_path("cipi/tokens/{$token}.json");

        if (!file_exists($tokenFile)) {
            abort(404, 'Download token not found or expired');
        }

        // Load token data
        $tokenData = json_decode(file_get_contents($tokenFile), true);

        if (!$tokenData || !isset($tokenData['file_path'])) {
            abort(404, 'Invalid download token');
        }

        $filePath = $tokenData['file_path'];

        // Check if file exists
        if (!file_exists($filePath)) {
            abort(404, 'Anonymized database file not found');
        }

        // Check expiration
        if (isset($tokenData['expires_at'])) {
            $expiresAt = \Carbon\Carbon::parse($tokenData['expires_at']);
            if (now()->isAfter($expiresAt)) {
                // Clean up expired token and file
                unlink($tokenFile);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                abort(410, 'Download link has expired');
            }
        }

        // Serve the file
        $fileName = 'anonymized_database_' . date('Y-m-d_H-i-s') . '.sql';

        return response()->file($filePath, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ])->deleteFileAfterSend(false); // Keep file for potential re-downloads
    }

    public function findUserByEmail(Request $request): JsonResponse
    {
        // Only enable if token is configured
        if (!config('cipi.anonymizer_enabled')) {
            return response()->json([
                'error' => 'Database anonymizer not configured',
                'message' => 'Set CIPI_ANONYMIZER_TOKEN in your environment to enable this feature'
            ], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 422);
        }

        $email = $request->input('email');

        try {
            // Find user by email
            $user = DB::table('users')->where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'error' => 'User not found',
                    'message' => "No user found with email: {$email}"
                ], 404);
            }

            return response()->json([
                'user_id' => $user->id,
                'email' => $user->email,
                'found_at' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Database query failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
