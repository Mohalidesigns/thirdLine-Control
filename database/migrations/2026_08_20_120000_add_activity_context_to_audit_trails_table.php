<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activity Log (CR3): widens audit_trails from a CRUD diff store into the
 * system-wide activity log — denormalised actor snapshot (survives user
 * renames/deletes), human label layer, HTTP context, batch grouping and the
 * tamper-evidence hash chain. audit_chain_head is the serialisation point
 * for chain writes: one row, locked FOR UPDATE per append.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_trails', function (Blueprint $table) {
            $table->string('actor_name')->nullable()->after('user_id');
            $table->string('actor_email')->nullable()->after('actor_name');
            $table->string('event_label')->nullable()->after('action');
            $table->string('subject_label')->nullable()->after('event_label');
            $table->text('description')->nullable()->after('subject_label');
            $table->string('method', 10)->nullable()->after('user_agent');
            $table->text('url')->nullable()->after('method');
            $table->string('route_name')->nullable()->after('url');
            $table->unsignedSmallInteger('status_code')->nullable()->after('route_name');
            $table->string('device_name', 120)->nullable()->after('status_code');
            $table->uuid('batch_id')->nullable()->after('device_name');
            $table->char('previous_hash', 64)->nullable()->after('batch_id');
            $table->char('row_hash', 64)->nullable()->after('previous_hash');

            $table->index('batch_id');
            $table->index('created_at');
        });

        Schema::create('audit_chain_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->char('last_hash', 64)->nullable();
            $table->unsignedBigInteger('last_audit_id')->nullable();
        });

        DB::table('audit_chain_head')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_head');

        Schema::table('audit_trails', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropIndex(['created_at']);
            $table->dropColumn([
                'actor_name', 'actor_email', 'event_label', 'subject_label',
                'description', 'method', 'url', 'route_name', 'status_code',
                'device_name', 'batch_id', 'previous_hash', 'row_hash',
            ]);
        });
    }
};
