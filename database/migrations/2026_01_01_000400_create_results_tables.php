<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Computed results. Results are materialised by an asynchronous run and are
 * only visible to students once the examination is published; publishing is
 * a single status flip, so it is O(1) regardless of examination size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
            // processing -> completed | failed
            $table->string('status', 16)->default('processing');
            $table->string('batch_id')->nullable();
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('students_processed')->default(0);
            $table->json('summary')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('triggered_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['examination_id', 'status']);
        });

        Schema::create('course_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('examination_course_id')->constrained()->cascadeOnDelete();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->string('grade', 4)->nullable();
            $table->decimal('grade_point', 4, 2)->default(0);
            $table->unsignedTinyInteger('credits');
            // pass | fail | absent | withheld
            $table->string('status', 16);
            $table->json('remarks')->nullable();
            $table->foreignId('result_run_id');
            $table->timestamps();

            $table->index(['examination_id', 'student_id']);
            $table->index(['examination_id', 'result_run_id']);
        });

        Schema::create('examination_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('credits_attempted');
            $table->unsignedSmallInteger('credits_earned');
            $table->decimal('sgpa', 4, 2)->nullable();
            // pass | fail | withheld
            $table->string('status', 16);
            $table->foreignId('result_run_id');
            $table->timestamps();

            $table->unique(['examination_id', 'student_id']);
            $table->index(['examination_id', 'result_run_id']);
            $table->index(['examination_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_results');
        Schema::dropIfExists('course_results');
        Schema::dropIfExists('result_runs');
    }
};
