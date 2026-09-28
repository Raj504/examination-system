<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ExaminationController;
use App\Http\Controllers\Api\MarkController;
use App\Http\Controllers\Api\MarkImportController;
use App\Http\Controllers\Api\ResultController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Student-facing, unauthenticated, rate-limited. Published results only.
    Route::get('public/examinations/{examCode}/results/{registrationNo}', [ResultController::class, 'publicShow'])
        ->middleware('throttle:60,1');

    Route::middleware(['auth.apikey', 'idempotent'])->group(function () {

        // Master data
        Route::get('programmes', [CatalogController::class, 'programmes']);
        Route::post('programmes', [CatalogController::class, 'storeProgramme']);
        Route::get('courses', [CatalogController::class, 'courses']);
        Route::post('courses', [CatalogController::class, 'storeCourse']);
        Route::get('students', [CatalogController::class, 'students']);
        Route::post('students', [CatalogController::class, 'storeStudents']);
        Route::get('students/{registrationNo}', [CatalogController::class, 'student']);

        // Examinations & structure
        Route::get('examinations', [ExaminationController::class, 'index']);
        Route::post('examinations', [ExaminationController::class, 'store']);
        Route::get('examinations/{examination}', [ExaminationController::class, 'show']);
        Route::get('examinations/{examination}/courses', [ExaminationController::class, 'courses']);
        Route::put('examinations/{examination}/courses/{courseCode}', [ExaminationController::class, 'upsertCourse']);
        Route::match(['put', 'post'], 'examinations/{examination}/courses', [ExaminationController::class, 'upsertCourse']);
        Route::post('examinations/{examination}/enrollments', [ExaminationController::class, 'enroll']);
        Route::get('examinations/{examination}/progress', [ExaminationController::class, 'progress']);

        // Lifecycle transitions
        Route::post('examinations/{examination}/open-marks-entry', [ExaminationController::class, 'openMarksEntry']);
        Route::post('examinations/{examination}/lock-marks', [ExaminationController::class, 'lockMarks']);
        Route::post('examinations/{examination}/unlock-marks', [ExaminationController::class, 'unlockMarks']);
        Route::post('examinations/{examination}/publish', [ExaminationController::class, 'publish']);

        // Marks
        Route::get('examinations/{examination}/marks', [MarkController::class, 'index']);
        Route::put('examinations/{examination}/marks', [MarkController::class, 'upsert']);

        // Bulk imports
        Route::get('examinations/{examination}/mark-imports', [MarkImportController::class, 'index']);
        Route::post('examinations/{examination}/mark-imports', [MarkImportController::class, 'store'])
            ->middleware('throttle:30,1');
        Route::get('mark-imports/{markImport}', [MarkImportController::class, 'show']);
        Route::post('mark-imports/{markImport}/retry', [MarkImportController::class, 'retry']);
        Route::get('mark-imports/{markImport}/errors', [MarkImportController::class, 'errors']);
        Route::get('mark-imports/{markImport}/errors.csv', [MarkImportController::class, 'errorsCsv']);

        // Result processing
        Route::post('examinations/{examination}/result-runs', [ResultController::class, 'process']);
        Route::get('examinations/{examination}/result-runs', [ResultController::class, 'runs']);
        Route::get('result-runs/{resultRun}', [ResultController::class, 'run']);
        Route::get('examinations/{examination}/results', [ResultController::class, 'index']);
        Route::get('examinations/{examination}/results/{registrationNo}', [ResultController::class, 'show']);
    });
});
