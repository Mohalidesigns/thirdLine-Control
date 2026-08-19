<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-01: the escalation/response loop writes into the SAME commentary
     * thread as everything else — extend the activity_type enum, don't
     * create a second thread table. Enum extension follows the established
     * ->change() pattern (2026_08_08_150011 / 2026_08_09_100013).
     */
    private const ORIGINAL = [
        'Comment', 'Status Change', 'Assignment', 'Extension Request',
        'Extension Decision', 'Evidence Upload', 'Escalation', 'Verification',
    ];

    private const ADDED = [
        'Escalation Issued', 'Escalation Withdrawn', 'Acknowledgement',
        'Response Submitted', 'Response Accepted', 'Response Rejected',
        'Reissued', 'Reminder Sent', 'Action Committed', 'Action Completed',
    ];

    public function up(): void
    {
        Schema::table('exception_activities', function (Blueprint $table) {
            $table->enum('activity_type', [...self::ORIGINAL, ...self::ADDED])->change();
        });
    }

    /**
     * Explicit rather than lossless-by-luck: rows written with the new
     * values are remapped to the closest original vocabulary before the
     * enum narrows, so the down() can never fail on a stray value.
     */
    public function down(): void
    {
        DB::table('exception_activities')
            ->whereIn('activity_type', self::ADDED)
            ->update(['activity_type' => 'Escalation']);

        Schema::table('exception_activities', function (Blueprint $table) {
            $table->enum('activity_type', self::ORIGINAL)->change();
        });
    }
};
