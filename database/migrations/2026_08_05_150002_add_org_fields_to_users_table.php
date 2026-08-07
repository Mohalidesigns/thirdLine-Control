<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('position')->nullable()->after('email');
            $table->unsignedBigInteger('unit_id')->nullable()->after('position')->index();
            $table->foreignId('reports_to')->nullable()->after('unit_id')->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('reports_to');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tenant_id', 'position', 'unit_id', 'reports_to', 'is_active', 'deleted_at']);
        });
    }
};
