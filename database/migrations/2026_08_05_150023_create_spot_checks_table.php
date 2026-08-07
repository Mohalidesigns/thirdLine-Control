<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spot_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference');
            $table->string('title');
            $table->text('scope_description')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('organisation_units')->nullOnDelete();
            $table->foreignId('process_id')->nullable()->constrained('business_processes')->nullOnDelete();
            $table->json('control_ids')->nullable();
            $table->boolean('is_surprise')->default(false);
            $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date_conducted');
            $table->foreignId('report_template_id')->nullable();
            $table->enum('status', ['Draft', 'In Progress', 'Completed', 'Report Issued'])->default('Draft');
            $table->timestamp('report_issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_checks');
    }
};
