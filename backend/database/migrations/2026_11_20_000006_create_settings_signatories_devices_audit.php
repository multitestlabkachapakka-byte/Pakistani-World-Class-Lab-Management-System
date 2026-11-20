<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group')->default('general'); // general, print, header, footer, numbering, sync
            $table->string('updated_by_device')->nullable();
            $table->unsignedBigInteger('rev')->default(1);
            $table->timestamps();
        });

        Schema::create('signatories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('qualification')->nullable();
            $table->string('designation')->nullable();
            $table->string('pmdc_number')->nullable();
            $table->string('signature_image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('updated_by_device')->nullable();
            $table->unsignedBigInteger('rev')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('device_code')->unique(); // P, M, W1, etc.
            $table->string('device_name');
            $table->string('platform'); // windows, android, ios, web
            $table->string('os_version')->nullable();
            $table->string('app_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('sync_page_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action'); // create, update, finalize, amend, delete, login, export, etc.
            $table->string('entity_type')->nullable(); // patient, report, test_definition...
            $table->uuid('entity_id')->nullable()->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('user_id')->nullable();
            $table->string('device_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });

        // Test profiles (panels) – many-to-many with test_definitions
        Schema::create('test_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('department')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('updated_by_device')->nullable();
            $table->unsignedBigInteger('rev')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('test_profile_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('test_profile_id')->index();
            $table->uuid('test_definition_id')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('test_profile_id')->references('id')->on('test_profiles')->cascadeOnDelete();
            $table->unique(['test_profile_id', 'test_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_profile_items');
        Schema::dropIfExists('test_profiles');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('signatories');
        Schema::dropIfExists('lab_settings');
    }
};
