<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Examination structure: an examination offers many courses, each course
 * offering has weighted assessment components, and students enroll per
 * course offering.
 *
 * `examination_id` is intentionally denormalised onto enrollments so every
 * hot query (marks entry, result processing, listing) can be scoped by
 * examination without joins, and so the large tables can later be
 * partitioned by examination (see README: Scaling considerations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examinations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('academic_year', 16);
            // draft -> marks_entry -> marks_locked -> processing -> results_ready -> published
            $table->string('status', 24)->default('draft');
            $table->foreignId('current_result_run_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('examination_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            // Minimum weighted percentage required to pass the course.
            $table->decimal('pass_percentage', 5, 2)->default(40);
            $table->timestamps();

            $table->unique(['examination_id', 'course_id']);
        });

        Schema::create('assessment_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_course_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);          // e.g. MID, END, LAB
            $table->string('name');
            $table->decimal('max_marks', 6, 2);
            $table->decimal('weight', 5, 2);     // percentage; components of a course sum to 100
            // Optional per-component minimum (e.g. "must score 30% in END exam").
            $table->decimal('min_pass_marks', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['examination_course_id', 'code']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('examination_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['examination_course_id', 'student_id']);
            // Result processing walks students of an examination in id order.
            $table->index(['examination_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('assessment_components');
        Schema::dropIfExists('examination_courses');
        Schema::dropIfExists('examinations');
    }
};
