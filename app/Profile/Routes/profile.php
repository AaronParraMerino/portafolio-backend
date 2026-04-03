<?php

use Illuminate\Support\Facades\Route;
use App\Profile\Controllers\ProfileController;

Route::prefix('profile')->group(function () {

    Route::get('{userId}', [ProfileController::class, 'show']);

    //Route::put('{userId}', [ProfileControllerMock::class, 'update']);

    //Route::patch('{userId}/visibility', [ProfileControllerMock::class, 'updateVisibility']);
});