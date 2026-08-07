<?php

namespace App\Http\Controllers;

use App\Models\OrganisationUnit;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'metrics' => $this->dashboardService->metrics(
                $request->only(['unit_id', 'severity']),
            ),
            'filters' => $request->only(['unit_id', 'severity']),
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
