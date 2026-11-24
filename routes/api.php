<?php

use App\Http\Controllers\Api\AthleteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DelayController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ResultController;
use App\Http\Controllers\Api\SduiController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    // Authentication
    Route::get('/auth/user', [AuthController::class, 'getUser']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh-token', [AuthController::class, 'refresh']);

    // Dashboard statistics and Server-Driven UI
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboards/{role}', [SduiController::class, 'show']);
    Route::get('/analytics', [SduiController::class, 'analytics']);

    // Events and schedule
    Route::apiResource('events', EventController::class);
    Route::get('/events/{event}/delays', [DelayController::class, 'index']);
    Route::post('/events/{event}/delays', [DelayController::class, 'store']);

    // Athletes
    Route::get('/events/{event}/athletes', [AthleteController::class, 'index']);
    Route::post('/events/{event}/athletes', [AthleteController::class, 'store']);
    Route::put('/athletes/{athlete}', [AthleteController::class, 'update']);
    Route::delete('/athletes/{athlete}', [AthleteController::class, 'destroy']);

    // Results and standings
    Route::get('/events/{event}/results', [ResultController::class, 'index']);
    Route::post('/events/{event}/results', [ResultController::class, 'store']);
    Route::get('/events/{event}/standings', [ResultController::class, 'standings']);
    Route::get('/standings/overall', [ResultController::class, 'overall']);
    Route::put('/results/{result}', [ResultController::class, 'update']);
    Route::delete('/results/{result}', [ResultController::class, 'destroy']);

    // Profile
    Route::get('/user/profile', [ProfileController::class, 'show']);
    Route::put('/user/profile', [ProfileController::class, 'update']);
    Route::post('/user/change-password', [ProfileController::class, 'changePassword']);

    // Synchronization
    Route::get('/sync/status', [SyncController::class, 'status']);
    Route::post('/sync', [SyncController::class, 'sync']);
    Route::post('/sync/upload', [SyncController::class, 'upload']);
    Route::get('/sync/download', [SyncController::class, 'download']);
    Route::get('/sync/conflicts', [SyncController::class, 'conflicts']);
});
