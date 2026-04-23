<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\EnlaceController;

//para hacerlo sin autenticacion: 
Route::prefix('enlaces')->group(function () {

//Route::prefix('enlaces')->middleware('auth:sanctum')->group(function () {

    Route::get('{userId}', [EnlaceController::class, 'index']);

    Route::post('{userId}', [EnlaceController::class, 'store']);

    Route::delete('{userId}/{idEnlace}', [EnlaceController::class, 'destroy']);

});