<?php

use App\Http\Controllers\AttemptController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DailyLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\MistakeController;
use App\Http\Controllers\PaperController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SampleDataController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SyllabusController;
use App\Http\Controllers\TheoryController;
use Illuminate\Support\Facades\Route;

// ---- Language switch (works before sign-in too) ------------------------------------------------
Route::get('/language/{locale}', [LanguageController::class, 'switch'])->name('language.switch');

// ---- Sign-in (the only public pages; the hosting health check is /up) -----------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

// ---- Everything else needs the shared account ---------------------------------------------
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/account/password', [AuthController::class, 'editPassword'])->name('account.password');
    Route::put('/account/password', [AuthController::class, 'updatePassword'])->name('account.password.update');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/daily-log', [DailyLogController::class, 'index'])->name('daily-log.index');
    Route::post('/daily-log', [DailyLogController::class, 'store'])->name('daily-log.store');

    Route::post('/sample-data', [SampleDataController::class, 'store'])->name('sample-data.store');
    Route::delete('/sample-data', [SampleDataController::class, 'destroy'])->name('sample-data.destroy');

    Route::get('/syllabus', [SyllabusController::class, 'index'])->name('syllabus.index');
    Route::post('/syllabus/units', [SyllabusController::class, 'storeUnit'])->name('syllabus.units.store');
    Route::post('/syllabus/topics', [SyllabusController::class, 'storeTopic'])->name('syllabus.topics.store');
    Route::put('/syllabus/topics/{topic}', [SyllabusController::class, 'updateTopic'])->name('syllabus.topics.update');
    Route::post('/syllabus/import', [SyllabusController::class, 'import'])->name('syllabus.import');
    Route::post('/syllabus/load-nie', [SyllabusController::class, 'loadNie'])->name('syllabus.load-nie');

    Route::get('/papers', [PaperController::class, 'index'])->name('papers.index');
    Route::post('/papers', [PaperController::class, 'storePaper'])->name('papers.store');
    Route::post('/papers/import', [PaperController::class, 'import'])->name('papers.import');
    Route::get('/papers/{paper}', [PaperController::class, 'show'])->name('papers.show');
    Route::put('/papers/{paper}', [PaperController::class, 'updatePaper'])->name('papers.update');
    Route::post('/papers/{paper}/questions', [PaperController::class, 'storeQuestion'])->name('questions.store');
    Route::put('/questions/{question}', [PaperController::class, 'updateQuestion'])->name('questions.update');

    Route::get('/attempts', [AttemptController::class, 'index'])->name('attempts.index');
    Route::get('/attempts/create', [AttemptController::class, 'create'])->name('attempts.create');
    Route::post('/attempts', [AttemptController::class, 'store'])->name('attempts.store');
    Route::get('/attempts/question/{question}', [AttemptController::class, 'history'])->name('attempts.history');
    Route::get('/attempts/{attempt}/edit', [AttemptController::class, 'edit'])->name('attempts.edit')->whereNumber('attempt');
    Route::put('/attempts/{attempt}', [AttemptController::class, 'update'])->name('attempts.update')->whereNumber('attempt');
    Route::get('/paper-attempts/create', [AttemptController::class, 'createPaper'])->name('paper-attempts.create');
    Route::get('/paper-attempts/{paperAttempt}/edit', [AttemptController::class, 'editPaper'])->name('paper-attempts.edit')->whereNumber('paperAttempt');
    Route::put('/paper-attempts/{paperAttempt}', [AttemptController::class, 'updatePaper'])->name('paper-attempts.update')->whereNumber('paperAttempt');
    Route::post('/paper-attempts', [AttemptController::class, 'storePaper'])->name('paper-attempts.store');

    Route::get('/theory', [TheoryController::class, 'index'])->name('theory.index');
    Route::post('/theory', [TheoryController::class, 'store'])->name('theory.store');
    Route::get('/theory/{entry}/edit', [TheoryController::class, 'edit'])->name('theory.edit')->whereNumber('entry');
    Route::put('/theory/{entry}', [TheoryController::class, 'update'])->name('theory.update')->whereNumber('entry');

    Route::get('/mistakes', [MistakeController::class, 'index'])->name('mistakes.index');
    Route::post('/mistakes', [MistakeController::class, 'store'])->name('mistakes.store');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

    // JSON helpers for the cascading dropdowns (session-authenticated)
    Route::prefix('api')->group(function () {
        Route::get('/papers', [PaperController::class, 'papersJson'])->name('api.papers');
        Route::get('/papers/{paper}/questions', [PaperController::class, 'questionsJson'])->name('api.questions');
        Route::get('/papers/{paper}/attempts', [AttemptController::class, 'paperAttemptsJson'])->name('api.paper-attempts');
        Route::get('/topics', [PaperController::class, 'topicsJson'])->name('api.topics');
    });
});
