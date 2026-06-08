<?php

use App\Http\Controllers\Api\HomePortfolioController;
use Illuminate\Support\Facades\Route;

Route::get('projects/public/{projectId}', [HomePortfolioController::class, 'projectDetail'])
    ->middleware('auth:sanctum');
