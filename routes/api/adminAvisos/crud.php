<?php

use App\Http\Controllers\Api\AdminAvisoController;
use Illuminate\Support\Facades\Route;

Route::post('/', [AdminAvisoController::class, 'store']);
Route::put('{idAviso}', [AdminAvisoController::class, 'update']);
Route::delete('{idAviso}', [AdminAvisoController::class, 'destroy']);
