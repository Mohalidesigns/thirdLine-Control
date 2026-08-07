<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exception_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exception_id')->constrained('control_exceptions')->cascadeOnDelete();
            $table->enum('activity_type', [
                'Comment', 'Status Change', 'Assignment', 'Extension Request',
                'Extension Decision', 'Evidence Upload', 'Escalation', 'Verification',
            ]);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['exception_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_activities');
    }
};
