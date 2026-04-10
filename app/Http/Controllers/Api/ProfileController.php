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
        $user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

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
        $user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->all();

        return response()->json(
            $this->service->updateVisibility($userId, $data)
        );
    }

    /**
     * Controller para manejar la subida de imágenes de perfil y banner.
     */

    public function uploadImage(Request $request, int $userId)
    {
        $user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->validate([
            'tipo' => 'required|in:profile,banner',
            'file' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120'
        ]);

        $response = $this->service->addImageProfileBanner(
            $userId,
            $request->file('file'),
            $request->tipo
        );

        return response()->json($response, $response['status'] ? 200 : 400);
    }

    /**
     * Controller para manejar la eliminación de imágenes de perfil y banner.
     */

    public function deleteImage(Request $request, int $userId)
    {
        $user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->validate([
            'tipo' => 'required|in:profile,banner'
        ]);

        $response = $this->service->deleteProfileBannerImage(
            $userId,
            $request->tipo
        );

        return response()->json($response, $response['status'] ? 200 : 400);
    }

    /**
     * Controller para manejar la actualización de imágenes de perfil y banner.
     */

    public function updateImage(Request $request, int $userId)
    {
        $user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->validate([
            'tipo' => 'required|in:profile,banner',
            'file' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120'
        ]);

        $response = $this->service->updateProfileBannerImage(
            $userId,
            $request->file('file'),
            $request->tipo
        );

        return response()->json($response, $response['status'] ? 200 : 400);
    }
}
