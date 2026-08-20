<?php

namespace App\Http\Controllers;

use App\Services\ConsolidationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupDashboardController extends Controller
{
    public function __construct(private ConsolidationService $consolidation) {}

    /**
     * The consolidated group position (16.1). The rollup itself filters to
     * the entities this user was explicitly granted — the controller adds
     * nothing and can leak nothing.
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('view group-dashboard'), 403);

        return Inertia::render('Group/Dashboard', [
            'rollup' => $this->consolidation->rollup($request->user()),
        ]);
    }
}
