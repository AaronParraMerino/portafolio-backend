<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\AdminAvisoService;
use Illuminate\Http\Request;

class AdminAvisoController extends Controller
{
    protected AdminAvisoService $service;

    public function __construct(AdminAvisoService $service)
    {
        $this->service = $service;
    }

    /**
     * Lista avisos para administracion
     */
    public function index(Request $request)
    {
        if ($response = $this->rejectIfNotAdmin()) {
            return $response;
        }

        $request->validate([
            'por_pagina' => 'nullable|integer|min:1|max:100',
            'estado' => 'nullable|in:activo,inactivo,eliminado',
            'prioridad' => 'nullable|in:baja,normal,alta,critica',
            'tipo' => 'nullable|string|max:80',
            'buscar' => 'nullable|string|max:150',
        ]);

        $filtros = $request->only([
            'estado',
            'prioridad',
            'tipo',
            'buscar',
        ]);

        $porPagina = (int) $request->query('por_pagina', 12);

        $response = $this->service->listar($filtros, $porPagina);

        return $this->jsonResponse($response);
    }

    /**
     * Muestra un aviso por id
     */
    public function show(int $idAviso)
    {
        if ($response = $this->rejectIfNotAdmin()) {
            return $response;
        }

        $response = $this->service->ver($idAviso);

        return $this->jsonResponse($response);
    }

    /**
     * Crea un aviso
     */
    public function store(Request $request)
    {
        if ($response = $this->rejectIfNotAdmin()) {
            return $response;
        }

        $request->validate([
            'tipo' => 'required|string|max:80',
            'titulo' => 'required|string|max:150',
            'mensaje' => 'required|string',

            'visible_desde' => 'nullable|date',
            'visible_hasta' => 'nullable|date',

            'estado' => 'nullable|in:activo,inactivo,eliminado',
            'prioridad' => 'nullable|in:baja,normal,alta,critica',
        ]);

        $user = auth()->user();

        $data = $request->only([
            'tipo',
            'titulo',
            'mensaje',
            'visible_desde',
            'visible_hasta',
            'estado',
            'prioridad',
        ]);

        $response = $this->service->crear($user->id_usuario, $data);

        return $this->jsonResponse($response, 201);
    }

    /**
     * Actualiza un aviso
     */
    public function update(Request $request, int $idAviso)
    {
        if ($response = $this->rejectIfNotAdmin()) {
            return $response;
        }

        $request->validate([
            'tipo' => 'sometimes|required|string|max:80',
            'titulo' => 'sometimes|required|string|max:150',
            'mensaje' => 'sometimes|required|string',

            'visible_desde' => 'sometimes|nullable|date',
            'visible_hasta' => 'sometimes|nullable|date',

            'estado' => 'sometimes|required|in:activo,inactivo,eliminado',
            'prioridad' => 'sometimes|required|in:baja,normal,alta,critica',
        ]);

        $data = $request->only([
            'tipo',
            'titulo',
            'mensaje',
            'visible_desde',
            'visible_hasta',
            'estado',
            'prioridad',
        ]);

        $response = $this->service->actualizar($idAviso, $data);

        return $this->jsonResponse($response);
    }

    /**
     * Cambia solo el estado del aviso
     */
    public function cambiarEstado(Request $request, int $idAviso)
    {
        if ($response = $this->rejectIfNotAdmin()) {
            return $response;
        }

        $request->validate([
            'estado' => 'required|in:activo,inactivo,eliminado',
        ]);

        $response = $this->service->cambiarEstado(
            $idAviso,
            $request->estado
        );

        return $this->jsonResponse($response);
    }

    /**
     * Cambia solo la prioridad del aviso
     */
    public function cambiarPrioridad(Request $request, int $idAviso)
    {
        if ($response = $this->rejectIfNotAdmin()) {
            return $response;
        }

        $request->validate([
            'prioridad' => 'required|in:baja,normal,alta,critica',
        ]);

        $response = $this->service->cambiarPrioridad(
            $idAviso,
            $request->prioridad
        );

        return $this->jsonResponse($response);
    }

    /**
     * Eliminacion logica del aviso
     */
    public function destroy(int $idAviso)
    {
        if ($response = $this->rejectIfNotAdmin()) {
            return $response;
        }

        $response = $this->service->eliminarLogico($idAviso);

        return $this->jsonResponse($response);
    }

    /**
     * Verifica que el usuario este autenticado y sea admin
     */
    private function rejectIfNotAdmin()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'status' => 'unauthenticated',
                'message' => 'Usuario no autenticado',
            ], 401);
        }

        if ($user->rol !== 'admin') {
            return response()->json([
                'status' => 'forbidden',
                'message' => 'No autorizado. Se requiere rol admin',
            ], 403);
        }

        return null;
    }

    /**
     * Respuesta JSON segun el status devuelto por el service
     */
    private function jsonResponse(array $response, int $successCode = 200)
    {
        $status = $response['status'] ?? null;

        $httpCode = match ($status) {
            'success' => $successCode,
            'not_found' => 404,
            'invalid_payload', 'invalid_filter' => 422,
            default => 400,
        };

        return response()->json($response, $httpCode);
    }
}
