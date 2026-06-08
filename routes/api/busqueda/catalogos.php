<?php

use App\Http\Controllers\Api\BusquedaController;
use Illuminate\Support\Facades\Route;

Route::get('/catalogos/profesiones', [BusquedaController::class, 'profesiones']);
Route::get('/catalogos/habilidades-blandas', [BusquedaController::class, 'habilidadesBlandas']);
Route::get('/catalogos/habilidades-tecnicas', [BusquedaController::class, 'habilidadesTecnicas']);
Route::get('/catalogos/cargos-experiencia', [BusquedaController::class, 'cargosExperiencia']);
Route::get('/catalogos/tecnologias-proyecto', [BusquedaController::class, 'tecnologiasProyecto']);
Route::get('/catalogos/tipos-proyecto', [BusquedaController::class, 'tiposProyecto']);
