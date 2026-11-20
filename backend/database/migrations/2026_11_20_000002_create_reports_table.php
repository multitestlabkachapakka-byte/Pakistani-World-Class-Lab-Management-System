<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('report_number')->unique()->index();
            $table->uuid('patient_id')->index();
            $table->uuid('doctor_id')->nullable()->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->string('status')->default('draft'); // draft, final, amended
            $table->string('sample_type')->nullable();
            $table->dateTime('collected_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->dateTime('reported_at')->nullable();
            $table->string('referred_by')->nullable();
            $table->text('clinical_notes')->nullable();
            $table->text('comments')->nullable();
            $table->uuid('finalized_by')->nullable();
            $table->json('snapshot')->nullable(); // frozen names/units/ranges at finalization
            $table->string('updated_by_device')->nullable();
            $table->unsignedBigInteger('rev')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->index(['status', 'reported_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
