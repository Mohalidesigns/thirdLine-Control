<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controls', function (Blueprint $table) {
            // Group vs entity library (Phase 9.1). A group control is the
            // master definition; distributing it creates entity children
            // that point back through parent_control_id.
            $table->enum('library_level', ['group', 'entity'])->default('entity')->after('status');
            $table->foreignId('parent_control_id')->nullable()->after('library_level')
                ->constrained('controls', indexName: 'fk_controls_parent_control')->nullOnDelete();
            $table->enum('function_grouping', ['Identify', 'Protect', 'Detect', 'Respond', 'Recover', 'Govern'])
                ->nullable()->after('parent_control_id');
            $table->enum('control_level', ['Strategic', 'Operational', 'Transactional'])
                ->nullable()->after('function_grouping');
            $table->enum('implementation_status', [
                'Not Started', 'In Progress', 'Implemented', 'Partially Implemented', 'Not Applicable',
            ])->default('Not Started')->after('control_level');
            $table->unsignedTinyInteger('implementation_progress')->default(0)->after('implementation_status');
            $table->boolean('is_distributable')->default(false)->after('implementation_progress');

            $table->index('library_level');
            $table->index('implementation_status');
        });
    }

    public function down(): void
    {
        Schema::table('controls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_control_id');
            $table->dropIndex(['library_level']);
            $table->dropIndex(['implementation_status']);
            $table->dropColumn([
                'library_level', 'function_grouping', 'control_level',
                'implementation_status', 'implementation_progress', 'is_distributable',
            ]);
        });
    }
};
