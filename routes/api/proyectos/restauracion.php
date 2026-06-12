<?php

use App\Http\Controllers\Api\Proyecto\ProyectoRestauracionController;
use Illuminate\Support\Facades\Route;

Route::post('/{id}/restore', [ProyectoRestauracionController::class, 'restore']);
Route::post('/{id}/restore-request', [ProyectoRestauracionController::class, 'requestRestore']);
Route::patch('/{id}/restore-request/{notificationId}', [ProyectoRestauracionController::class, 'respond']);
Route::delete('/{id}/repositories/{repositoryId}/release', [ProyectoRestauracionController::class, 'releaseRepository']);
