<?php

use App\Http\Controllers\Api\AthleteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ResultController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/user', [AuthController::class, 'getUser']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::apiResource('events', EventController::class);

    Route::get('/events/{event}/athletes', [AthleteController::class, 'index']);
    Route::post('/events/{event}/athletes', [AthleteController::class, 'store']);
    Route::put('/athletes/{athlete}', [AthleteController::class, 'update']);
    Route::delete('/athletes/{athlete}', [AthleteController::class, 'destroy']);

    Route::get('/events/{event}/results', [ResultController::class, 'index']);
    Route::post('/events/{event}/results', [ResultController::class, 'store']);
    Route::get('/events/{event}/standings', [ResultController::class, 'standings']);
    Route::put('/results/{result}', [ResultController::class, 'update']);
    Route::delete('/results/{result}', [ResultController::class, 'destroy']);

    Route::get('/user/profile', [ProfileController::class, 'show']);
    Route::put('/user/profile', [ProfileController::class, 'update']);
    Route::post('/user/change-password', [ProfileController::class, 'changePassword']);

    Route::get('/sync/status', [SyncController::class, 'status']);
    Route::post('/sync', [SyncController::class, 'sync']);
});
