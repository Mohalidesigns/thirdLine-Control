<?php

namespace App\Services;

use App\Models\CsaQuestionnaire;
use App\Models\Document;
use App\Models\Framework;
use App\Models\ReportTemplate;
use App\Models\TestScript;
use Illuminate\Database\Eloquent\Model;

/**
 * Version comparison across every HasVersions model (9.8). Resolution is
 * alias-based so the diff endpoint mirrors the audit-trail entity route.
 */
class VersioningService
{
    public const ALIASES = [
        'test-script' => TestScript::class,
        'document' => Document::class,
        'questionnaire' => CsaQuestionnaire::class,
        'framework' => Framework::class,
        'report-template' => ReportTemplate::class,
    ];

    public function resolve(string $alias, int $id): Model
    {
        $class = self::ALIASES[$alias] ?? abort(404, "Unknown versioned type {$alias}.");

        return $class::findOrFail($id);
    }

    /** Side-by-side comparison payload: versions list + field-level diff. */
    public function compare(Model $model, ?int $from, ?int $to): array
    {
        $versions = $model->objectVersions()->with('author:id,name')->get();

        $latest = (int) $versions->max('version_no');
        $to = $to ?: $latest;
        $from = $from ?: max(1, $to - 1);

        return [
            'versions' => $versions->map(fn ($v) => [
                'version_no' => $v->version_no,
                'change_reason' => $v->change_reason,
                'author' => $v->author?->name,
                'created_at' => $v->created_at,
            ])->values()->all(),
            'from' => $from,
            'to' => $to,
            'diff' => $model->diffVersions($from, $to),
        ];
    }
}
