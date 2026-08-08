<?php

namespace App\Http\Controllers;

use App\Services\VersioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VersionController extends Controller
{
    public function __construct(private VersioningService $versioningService) {}

    /** Fallback permissions for aliases whose model has no view policy. */
    private const FALLBACK_PERMISSIONS = [
        'test-script' => 'view tests',
        'questionnaire' => 'view campaigns',
        'report-template' => 'export reports',
    ];

    /**
     * Field-level version comparison for any versioned object. Access
     * mirrors the record's own view policy — no separate permission.
     */
    public function diff(Request $request, string $alias, int $id)
    {
        $record = $this->versioningService->resolve($alias, $id);

        if (Gate::getPolicyFor($record)) {
            Gate::authorize('view', $record);
        } else {
            abort_unless(
                $request->user()->can(self::FALLBACK_PERMISSIONS[$alias] ?? 'manage settings'),
                403,
            );
        }

        return response()->json($this->versioningService->compare(
            $record,
            $request->integer('from') ?: null,
            $request->integer('to') ?: null,
        ));
    }
}
