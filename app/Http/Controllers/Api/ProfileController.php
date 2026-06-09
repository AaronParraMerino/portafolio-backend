<?php

namespace App\Http\Controllers\Api;

use App\Services\api\ContenidoTraduccionService;
use App\Services\api\ProfileService;
use Illuminate\Http\Request;

class ProfileController
{
    protected ProfileService $service;
    protected ContenidoTraduccionService $traduccionService;

    public function __construct(
        ProfileService $service,
        ContenidoTraduccionService $traduccionService
    ) {
        $this->service = $service;
        $this->traduccionService = $traduccionService;
    }

    /**
     * Retorna el perfil completo de un usuario en formato JSON
     * Delega toda la lógica al servicio
     */

    public function show(Request $request, int $userId)
    {
        $lang = $request->query('lang', 'es');

        $perfil = $this->service->getProfile($userId);

        return response()->json(
            $this->traducirPerfilPayload($perfil, $userId, $lang)
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

    public function updatePortfolioVisibility(Request $request, int $userId)
    {
        $user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->validate([
            'portfolio_publico' => 'required|boolean'
        ]);

        return response()->json(
            $this->service->updatePortfolioVisibility(
                $userId,
                filter_var($request->portfolio_publico, FILTER_VALIDATE_BOOLEAN)
            )
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

    private function traducirPerfilPayload(mixed $payload, int $userId, string $lang): mixed
    {
        if (is_object($payload) && method_exists($payload, 'toArray')) {
            $payload = $payload->toArray();
        }

        if (! is_array($payload)) {
            return $payload;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload['data'] = $this->traducirPerfilPayload($payload['data'], $userId, $lang);

            return $payload;
        }

        if (isset($payload['perfil']) && is_array($payload['perfil'])) {
            $payload['perfil'] = $this->traducirPerfilPayload($payload['perfil'], $userId, $lang);
        }

        $perfilId = $payload['id_perfil']
            ?? ($payload['perfil']['id_perfil'] ?? null)
            ?? \DB::table('perfiles')
                ->where('usuario_id', $userId)
                ->value('id_perfil')
            ?? $userId;

        foreach (['profesion', 'biografia'] as $campo) {
            if (array_key_exists($campo, $payload)) {
                $payload[$campo] = $this->traduccionService->traducirCampo(
                    'perfil',
                    $perfilId,
                    $campo,
                    $payload[$campo],
                    $lang
                );
            }
        }

        return $payload;
    }

}
