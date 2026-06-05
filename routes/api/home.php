<?php

use App\Http\Controllers\Api\HomePortfolioController;
use Illuminate\Support\Facades\Route;

Route::prefix('home')->group(function () {
    Route::get('/stats', [HomePortfolioController::class, 'stats']);
    Route::get('/desarrolladores', [HomePortfolioController::class, 'developers']);
    Route::get('/portafolios-destacados', [HomePortfolioController::class, 'featured']);
    Route::get('/portafolios/{userId}', [HomePortfolioController::class, 'show']);
});
