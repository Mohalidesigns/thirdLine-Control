<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CR-04 §F.1. A finding's recommendation becomes tracked work — an
 * improvement action with an owner, a due date and independent
 * verification — rather than a paragraph in a report nobody chases.
 *
 * `improvement_actions.source_type` is a real MySQL enum, so the new
 * source needs the column widened; additive, and existing rows are
 * untouched.
 */
return new class extends Migration
{
    private const EXISTING = ['test', 'csa', 'spot_check', 'incident', 'audit', 'exception', 'survey', 'manual'];

    public function up(): void
    {
        Schema::table('improvement_actions', function (Blueprint $table) {
            $table->enum('source_type', [...self::EXISTING, 'investigation'])->default('manual')->change();
        });
    }

    /**
     * Narrowing an enum cannot keep a value the narrowed enum has no room
     * for, so investigation-sourced actions move to 'manual' before the
     * ALTER — otherwise MySQL truncates the column mid-rollback. The
     * provenance is genuinely lost on the way down; the finding still
     * carries improvement_action_id in the other direction.
     */
    public function down(): void
    {
        DB::table('improvement_actions')
            ->where('source_type', 'investigation')
            ->update(['source_type' => 'manual']);

        Schema::table('improvement_actions', function (Blueprint $table) {
            $table->enum('source_type', self::EXISTING)->default('manual')->change();
        });
    }
};
