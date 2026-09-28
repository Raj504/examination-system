<?php

use App\Http\Controllers\Web\ExamPageController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\SetupPageController;
use Illuminate\Support\Facades\Route;

// Simple server-rendered pages (Blade). They call the same services as the API.

// Examinations
Route::get('/', [ExamPageController::class, 'index'])->name('exams.index');
Route::post('/examinations', [ExamPageController::class, 'store'])->name('exams.store');
Route::get('/examinations/{exam}', [ExamPageController::class, 'show'])->name('exams.show');
Route::post('/examinations/{exam}/status', [ExamPageController::class, 'changeStatus'])->name('exams.status');
Route::post('/examinations/{exam}/courses', [ExamPageController::class, 'addCourse'])->name('exams.courses');
Route::post('/examinations/{exam}/enroll', [ExamPageController::class, 'enroll'])->name('exams.enroll');
Route::post('/examinations/{exam}/marks', [ExamPageController::class, 'storeMark'])->name('exams.marks.store');
Route::get('/examinations/{exam}/marks/{markId}/edit', [ExamPageController::class, 'editMark'])->name('exams.marks.edit');
Route::put('/examinations/{exam}/marks/{markId}', [ExamPageController::class, 'updateMark'])->name('exams.marks.update');
Route::post('/examinations/{exam}/uploads', [ExamPageController::class, 'uploadMarks'])->name('exams.imports.store');
Route::get('/examinations/{exam}/uploads/{import}', [ExamPageController::class, 'importErrors'])->name('exams.imports.errors');
Route::get('/examinations/{exam}/results/{registrationNo}', [ExamPageController::class, 'studentResult'])->name('exams.result');

// Programmes, courses and students
Route::get('/setup', [SetupPageController::class, 'show'])->name('setup');
Route::post('/setup/programmes', [SetupPageController::class, 'storeProgramme'])->name('setup.programme');
Route::post('/setup/courses', [SetupPageController::class, 'storeCourse'])->name('setup.course');
Route::post('/setup/students', [SetupPageController::class, 'storeStudents'])->name('setup.students');

// Student result lookup (published results only)
Route::get('/results', [PortalController::class, 'show'])->name('portal');

// Swagger UI for the JSON API (routes/api.php)
Route::view('/docs', 'docs')->name('docs');
