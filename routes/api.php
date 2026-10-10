<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HelloWorldController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloWorldController::class);

Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', 'register')->name('register');
        Route::post('/login', 'login')->name('login');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', 'logout')->name('logout');
        Route::post('/logout-all', 'logoutAll')->name('logout-all');
        Route::get('/me', 'me')->name('me');
    });
});
