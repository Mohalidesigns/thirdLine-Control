<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('linked_type');
            $table->unsignedBigInteger('linked_id');
            $table->string('file_name');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->boolean('contains_personal_data')->default(false);
            $table->json('personal_data_categories')->nullable();
            $table->enum('classification', ['Public', 'Internal', 'Confidential', 'Restricted'])->default('Internal');
            $table->foreignId('retention_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->date('retention_expiry_date')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->text('legal_hold_reason')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('disposal_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disposal_requested_at')->nullable();
            $table->foreignId('disposal_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disposed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['linked_type', 'linked_id']);
            $table->index(['tenant_id', 'retention_expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};
