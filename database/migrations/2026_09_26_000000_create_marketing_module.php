<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing_settings', function (Blueprint $t) {
            $t->unsignedInteger('company_id')->primary();
            $t->json('config');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
        Schema::create('marketing_opportunities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('company_id')->index();
            $t->unsignedInteger('client_id');
            $t->unsignedInteger('contact_id');
            $t->unsignedInteger('quote_id')->nullable();
            $t->unsignedInteger('owner_id');
            $t->string('title');
            $t->string('stage_id', 64);
            $t->decimal('amount', 18, 4)->default(0);
            $t->unsignedInteger('currency_id');
            $t->date('expected_close')->nullable();
            $t->string('source', 64)->nullable();
            $t->string('campaign_id', 64)->nullable();
            $t->string('locale', 5)->default('en');
            $t->text('notes')->nullable();
            $t->text('lost_reason')->nullable();
            $t->boolean('consent')->default(false);
            $t->string('consent_source')->nullable();
            $t->timestamp('consent_at')->nullable();
            $t->boolean('automatic')->default(false);
            $t->boolean('archived')->default(false);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->index(['company_id', 'stage_id', 'archived']);
            $t->index(['company_id', 'contact_id']);
        });
        Schema::create('marketing_activities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('company_id');
            $t->uuid('opportunity_id');
            $t->unsignedInteger('user_id');
            $t->string('title');
            $t->string('kind', 16);
            $t->string('state', 16)->default('pending');
            $t->timestamp('due_at');
            $t->string('template_id', 64)->nullable();
            $t->text('notes')->nullable();
            $t->string('sequence_key')->nullable();
            $t->json('snapshot')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('opportunity_id')->references('id')->on('marketing_opportunities')->cascadeOnDelete();
            $t->unique(['opportunity_id', 'sequence_key']);
            $t->index(['company_id', 'state', 'due_at']);
            $t->index(['company_id', 'opportunity_id']);
        });
        Schema::create('marketing_suppressions', function (Blueprint $t) {
            $t->unsignedInteger('company_id');
            $t->string('email');
            $t->timestamp('created_at');
            $t->primary(['company_id', 'email']);
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }
    public function down(): void
    {
        foreach (['marketing_suppressions', 'marketing_activities', 'marketing_opportunities', 'marketing_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
