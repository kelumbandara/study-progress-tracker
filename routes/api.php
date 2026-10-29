<?php

use App\Http\Controllers\Api\V1\AttemptController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\DailyLogController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MistakeController;
use App\Http\Controllers\Api\V1\PaperController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SyllabusController;
use App\Http\Controllers\Api\V1\TheoryController;
use Illuminate\Support\Facades\Route;

/*
| Phone app API (Expo / React Native). Served under /api/v1, JSON only, bearer-token authenticated.
| Everything except sign-in needs "Authorization: Bearer <token>". Business rules live in the same services as the website.
*/
Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::get('/bootstrap', [BootstrapController::class, 'show']);
        Route::get('/dashboard', [DashboardController::class, 'show']);

        Route::get('/daily-logs', [DailyLogController::class, 'index']);
        Route::get('/daily-logs/{date}', [DailyLogController::class, 'show'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');
        Route::put('/daily-logs/{date}', [DailyLogController::class, 'upsert'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');

        Route::get('/syllabus', [SyllabusController::class, 'index']);
        Route::put('/syllabus/topics/{topic}', [SyllabusController::class, 'updateTopic'])->whereNumber('topic');

        Route::get('/papers', [PaperController::class, 'index']);
        Route::post('/papers', [PaperController::class, 'storePaper']);
        Route::get('/papers/{paper}/questions', [PaperController::class, 'questions'])->whereNumber('paper');
        Route::post('/papers/{paper}/questions', [PaperController::class, 'storeQuestion'])->whereNumber('paper');
        Route::get('/papers/{paper}/paper-attempts', [PaperController::class, 'paperAttempts'])->whereNumber('paper');
        Route::get('/topics', [PaperController::class, 'topics']);

        Route::get('/attempts', [AttemptController::class, 'index']);
        Route::post('/attempts', [AttemptController::class, 'store']);
        Route::put('/attempts/{attempt}', [AttemptController::class, 'update'])->whereNumber('attempt');
        Route::get('/questions/{question}/attempts', [AttemptController::class, 'history'])->whereNumber('question');
        Route::get('/paper-attempts', [AttemptController::class, 'paperIndex']);
        Route::post('/paper-attempts', [AttemptController::class, 'paperStore']);
        Route::put('/paper-attempts/{paperAttempt}', [AttemptController::class, 'paperUpdate'])->whereNumber('paperAttempt');

        Route::get('/theory', [TheoryController::class, 'index']);
        Route::post('/theory', [TheoryController::class, 'store']);
        Route::put('/theory/{entry}', [TheoryController::class, 'update'])->whereNumber('entry');

        Route::get('/mistakes', [MistakeController::class, 'index']);
        Route::post('/mistakes', [MistakeController::class, 'store']);

        Route::get('/reports', [ReportController::class, 'show']);
    });
});
