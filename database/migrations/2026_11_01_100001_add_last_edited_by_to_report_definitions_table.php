<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who last changed a definition's layout, so the approver can be held
     * apart from the editor as well as from the author.
     */
    public function up(): void
    {
        Schema::table('report_definitions', function (Blueprint $table) {
            $table->foreignId('last_edited_by')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
        });

        DB::table('report_definitions')->where('is_system', false)->update(['last_edited_by' => DB::raw('owner_id')]);
    }

    public function down(): void
    {
        Schema::table('report_definitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_edited_by');
        });
    }
};
