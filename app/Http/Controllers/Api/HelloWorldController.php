<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class HelloWorldController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(message: 'Hello World');
    }
}
