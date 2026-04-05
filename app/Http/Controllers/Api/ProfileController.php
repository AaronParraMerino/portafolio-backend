<?php

namespace App\Http\Controllers\Api;

use App\Services\api\ProfileService;
use Illuminate\Http\Request;

class ProfileController
{
    protected ProfileService $service;

    public function __construct(ProfileService $service)
    {
        $this->service = $service;
    }

    /**
     * Retorna el perfil completo de un usuario en formato JSON
     * Delega toda la lógica al servicio
     */

    public function show(int $userId)
    {
        return response()->json(
            $this->service->getProfile($userId)
        );
    }

    /**
     * Actualiza datos del perfil del usuario.
     * Filtra únicamente los campos permitidos
     * Evita que el cliente envíe campos no controlados.
     * Delega la lógica de actualización al servicio.
     */
    public function update(Request $request, int $userId)
    {
        $data = $request->only([
            'correo',
            'nombre',
            'apellido',
            'profesion',
            'telefono',
            'biografia',
            'ciudad',
            'pais',
        ]);

        return response()->json(
            $this->service->updateProfile($userId, $data)
        );
    }

    /**
     * Actualiza la visibilidad de los campos del perfil.
     * - Recibe un payload dinámico (campo => visibilidad).
     * - No aplica filtrado explícito
     * - Delega completamente la lógica al servicio.
     */

    public function updateVisibility(Request $request, int $userId)
    {
        $data = $request->all();

        return response()->json(
            $this->service->updateVisibility($userId, $data)
        );
    }
}