<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_brandings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()
                ->constrained('tenants', indexName: 'fk_tenant_brandings_tenant')->cascadeOnDelete();
            $table->string('logo_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('primary_colour', 7)->nullable();   // #RRGGBB — AEGIS default applies when null
            $table->string('accent_colour', 7)->nullable();
            $table->string('login_background_path')->nullable();
            $table->string('product_name')->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_phone', 50)->nullable();
            $table->text('report_header_html')->nullable();
            $table->text('report_footer_html')->nullable();
            $table->string('email_from_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_brandings');
    }
};
