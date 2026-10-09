<?php

namespace Cipi\Agent\Http\Controllers;

use Cipi\Agent\Support\HealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $report = HealthReport::build();

        return response()->json($report, $report['status'] === 'healthy' ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
