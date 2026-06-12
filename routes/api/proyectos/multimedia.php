<?php

use App\Http\Controllers\Api\Proyecto\ProyectoMediaController;
use Illuminate\Support\Facades\Route;

Route::post('/{id}/images', [ProyectoMediaController::class, 'uploadImages']);
Route::delete('/{id}/images', [ProyectoMediaController::class, 'deleteImages']);
Route::patch('/{id}/images/reorder', [ProyectoMediaController::class, 'reorderImages']);
Route::post('/{id}/images/repair-variants', [ProyectoMediaController::class, 'repairImageVariants']);

Route::post('/{id}/documents', [ProyectoMediaController::class, 'uploadDocuments']);
Route::delete('/{id}/documents', [ProyectoMediaController::class, 'deleteDocuments']);
Route::patch('/{id}/documents/reorder', [ProyectoMediaController::class, 'reorderDocuments']);
