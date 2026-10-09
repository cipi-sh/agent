<?php

namespace Cipi\Agent\Http\Controllers;

use Cipi\Agent\Anonymizer\Config;
use Cipi\Agent\Anonymizer\ConfigException;
use Cipi\Agent\Jobs\AnonymizeDatabaseJob;
use Cipi\Agent\Support\AppHome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class DatabaseController extends Controller
{
    public function startAnonymization(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'gzip' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 422);
        }

        if (AppHome::user() === null) {
            return response()->json(['error' => 'App user not configured'], 500);
        }

        $configPath = AppHome::anonymizationConfig();
        if ($configPath === null) {
            return response()->json([
                'error' => 'Configuration file not found',
                'message' => 'Place your anonymization.json on the server at one of: '.implode(', ', AppHome::anonymizationConfigCandidates()).' (php artisan cipi:init-anonymize --scan creates one).',
            ], 404);
        }

        try {
            Config::load($configPath);
        } catch (ConfigException $e) {
            return response()->json(['error' => 'Invalid configuration', 'message' => 'anonymization.json is not valid', 'details' => $e->errors], 400);
        }

        $email = (string) $request->input('email');
        $gzip = $request->has('gzip') ? $request->boolean('gzip') : null;

        AnonymizeDatabaseJob::dispatch($email, $configPath, null, $gzip);

        return response()->json([
            'status' => 'queued',
            'message' => 'Database anonymization job has been queued. You will receive an email with download instructions when complete.',
            'email' => $email,
            'link_valid_minutes' => (int) config('cipi.anonymizer_ttl', 15),
        ]);
    }

    public function downloadAnonymized(Request $request, string $token): Response
    {
        if (! config('cipi.anonymizer_enabled')) {
            abort(404);
        }
        if (! preg_match('/^[A-Za-z0-9]{32,128}$/', $token)) {
            abort(404, 'Download token not found or expired');
        }

        $tokenFile = storage_path("cipi/tokens/{$token}.json");
        if (! is_file($tokenFile)) {
            abort(404, 'Download token not found or expired');
        }

        $data = json_decode((string) file_get_contents($tokenFile), true);
        if (! is_array($data) || empty($data['file_path'])) {
            abort(404, 'Invalid download token');
        }

        $filePath = (string) $data['file_path'];
        if (! str_starts_with(realpath($filePath) ?: '', realpath(storage_path('cipi')) ?: '/-') || ! is_file($filePath)) {
            abort(404, 'Anonymized database file not found');
        }

        if (isset($data['expires_at']) && now()->isAfter(\Carbon\Carbon::parse($data['expires_at']))) {
            @unlink($tokenFile);
            @unlink($filePath);
            abort(410, 'Download link has expired');
        }

        $gzip = str_ends_with($filePath, '.gz');
        $fileName = 'anonymized_database_'.date('Y-m-d_H-i-s').'.sql'.($gzip ? '.gz' : '');

        return response()->file($filePath, [
            'Content-Type' => $gzip ? 'application/gzip' : 'application/sql',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function findUserByEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['email' => 'required|email']);
        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 422);
        }

        $email = (string) $request->input('email');

        try {
            $user = DB::table(config('cipi.anonymizer_users_table', 'users'))->where('email', $email)->first();
            if (! $user) {
                return response()->json(['error' => 'User not found', 'message' => "No user found with email: {$email}"], 404);
            }

            return response()->json([
                'user_id' => $user->id ?? null,
                'email' => $user->email ?? $email,
                'found_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Database query failed', 'message' => $e->getMessage()], 500);
        }
    }
}
