<?php

use App\Http\Controllers\Api\HelloWorldController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloWorldController::class);
