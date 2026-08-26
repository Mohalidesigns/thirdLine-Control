<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-03 §C.4/§C.6: the control officer who EXECUTES this desk's or
     * branch's tasks, distinct from owner_id, which CR-02 defined as the
     * second-line relationship officer. On many desks they are the same
     * person; on a large branch they are not, and generating a hundred
     * daily tasks into the relationship officer's queue would be wrong.
     *
     * Assignment resolves default_officer_id → owner_id → the unit head →
     * the control owner, first hit wins.
     */
    public function up(): void
    {
        Schema::table('control_entities', function (Blueprint $table) {
            $table->foreignId('default_officer_id')->nullable()->after('owner_id')
                ->constrained('users')->nullOnDelete();
            // Rows the checklist importer created, so an operator can tell
            // a desk the bank defined from one the workbook implied.
            $table->boolean('is_import_created')->default(false)->after('is_template');
        });
    }

    public function down(): void
    {
        Schema::table('control_entities', function (Blueprint $table) {
            $table->dropColumn('is_import_created');
            $table->dropConstrainedForeignId('default_officer_id');
        });
    }
};
