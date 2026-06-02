<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\UsuarioAvisoService;

class UsuarioAvisoController extends Controller
{
    protected UsuarioAvisoService $service;

    public function __construct(UsuarioAvisoService $service)
    {
        $this->service = $service;
    }

    /**
     * Lista todos los avisos visibles para usuarios
     */
    public function index()
    {
        return response()->json(
            $this->service->listarVisibles()
        );
    }

    /**
     * Cuenta avisos visibles actuales
     */
    public function count()
    {
        return response()->json(
            $this->service->contarVisibles()
        );
    }
}