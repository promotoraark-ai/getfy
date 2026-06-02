<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Services\PartnerDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartnerDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user->usesPartnerPanel()) {
            abort(403);
        }

        $period = $request->query('period', 'hoje');
        $metrics = app(PartnerDashboardService::class)->metricsFor($user, (string) $period);

        return Inertia::render('Partner/Dashboard', $metrics);
    }
}
