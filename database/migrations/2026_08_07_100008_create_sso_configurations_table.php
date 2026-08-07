<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_sso_configurations_tenant')->cascadeOnDelete();
            $table->enum('protocol', ['saml2', 'oidc']);
            $table->string('display_name');

            // SAML 2.0 (IdP side)
            $table->string('entity_id')->nullable();
            $table->string('sso_url')->nullable();
            $table->string('slo_url')->nullable();
            $table->text('x509_cert')->nullable();            // encrypted cast

            // OIDC
            $table->string('oidc_issuer')->nullable();
            $table->string('oidc_client_id')->nullable();
            $table->text('oidc_client_secret')->nullable();   // encrypted cast
            $table->string('oidc_discovery_url')->nullable();

            $table->json('attribute_map')->nullable();
            $table->boolean('jit_provisioning')->default(false);
            $table->foreignId('default_role_id')->nullable()
                ->constrained('roles', indexName: 'fk_sso_configurations_role')->nullOnDelete();
            $table->json('allowed_email_domains')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('verified_at')->nullable();     // last successful test-connection

            // Maker-checker on authentication configuration (Phase 7 rule):
            // nothing takes effect until a second System Administrator approves.
            $table->foreignId('created_by')
                ->constrained('users', indexName: 'fk_sso_configurations_creator');
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', indexName: 'fk_sso_configurations_approver');
            $table->timestamp('approved_at')->nullable();
            $table->json('pending_changes')->nullable();
            $table->foreignId('pending_changes_by')->nullable()
                ->constrained('users', indexName: 'fk_sso_configurations_proposer');
            $table->timestamp('pending_changes_at')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
            $table->index('is_enabled');
            $table->index('protocol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_configurations');
    }
};
