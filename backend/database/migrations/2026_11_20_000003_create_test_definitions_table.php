<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique()->index();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('department')->index(); // Hematology, Clinical Chemistry, etc.
            $table->json('aliases')->nullable(); // CBC, Blood CP, FBC ...
            $table->string('sample_type')->nullable();
            $table->string('methodology')->nullable();
            $table->boolean('is_profile')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('verification_status')->default('VERIFY REQUIRED'); // VERIFIED / VERIFY REQUIRED
            $table->date('last_verified')->nullable();
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('updated_by_device')->nullable();
            $table->unsignedBigInteger('rev')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department', 'is_active']);
        });

        Schema::create('test_parameters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('test_definition_id')->index();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('unit')->nullable();
            $table->string('data_type')->default('numeric'); // numeric, text, qualitative
            $table->boolean('is_calculated')->default(false);
            $table->string('calculation_formula')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('print_by_default')->default(true);
            $table->timestamps();

            $table->foreign('test_definition_id')->references('id')->on('test_definitions')->cascadeOnDelete();
        });

        Schema::create('reference_ranges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('test_parameter_id')->index();
            $table->string('gender')->nullable(); // male, female, both
            $table->unsignedSmallInteger('age_min_days')->nullable();
            $table->unsignedSmallInteger('age_max_days')->nullable();
            $table->string('age_label')->nullable(); // Adult, Pediatric, etc.
            $table->decimal('low', 12, 4)->nullable();
            $table->decimal('high', 12, 4)->nullable();
            $table->decimal('critical_low', 12, 4)->nullable();
            $table->decimal('critical_high', 12, 4)->nullable();
            $table->string('text_normal')->nullable(); // for qualitative: Negative, Non-reactive
            $table->string('range_text')->nullable(); // display string
            $table->string('source')->nullable();
            $table->string('verification_status')->default('VERIFY REQUIRED');
            $table->timestamps();

            $table->foreign('test_parameter_id')->references('id')->on('test_parameters')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_ranges');
        Schema::dropIfExists('test_parameters');
        Schema::dropIfExists('test_definitions');
    }
};
