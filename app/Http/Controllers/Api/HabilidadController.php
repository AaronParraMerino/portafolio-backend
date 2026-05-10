<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\HabilidadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HabilidadController extends Controller
{
    public function __construct(private readonly HabilidadService $habilidadService)
    {
    }

    public function catalog(Request $request): JsonResponse
    {
        $tipo = $request->query('tipo');

        if ($tipo && !in_array($tipo, ['tecnica', 'blanda'])) {
            return response()->json([
                'message' => 'Tipo inválido. Use tecnica o blanda.'
            ], 422);
        }

        $habilidades = $this->habilidadService->getCatalog($tipo);

        return response()->json($habilidades);
    }

    public function storeCatalog(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', 'in:tecnica,blanda'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $habilidad = $this->habilidadService->createCatalog($validator->validated());

        return response()->json([
            'message' => 'Habilidad creada correctamente',
            'data' => $habilidad,
        ], 201);
    }

    public function indexUserSkills(int $userId): JsonResponse
    {
        $habilidades = $this->habilidadService->getByUserId($userId);

        return response()->json($habilidades);
    }

    public function showUserSkill(int $userId, int $id): JsonResponse
    {
        $habilidad = $this->habilidadService->findOwnedById($userId, $id);

        if (! $habilidad) {
            return response()->json([
                'message' => 'Habilidad del usuario no encontrada'
            ], 404);
        }

        return response()->json($habilidad);
    }

    public function storeUserSkill(Request $request, int $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'habilidad_id' => ['nullable', 'integer', 'exists:habilidades,id_habilidad'],
            'nombre' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', 'in:tecnica,blanda'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'nivel' => ['required', 'in:basico,intermedio,avanzado,experto'],
            'es_visible' => ['sometimes', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if (!$request->habilidad_id && !$request->nombre) {
                $validator->errors()->add('nombre', 'Debe enviar habilidad_id o nombre.');
            }

            if (!$request->habilidad_id && !$request->tipo) {
                $validator->errors()->add('tipo', 'Debe enviar el tipo cuando cree una habilidad nueva.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $habilidad = $this->habilidadService->assignToUser($userId, $validator->validated());

            return response()->json([
                'message' => 'Habilidad registrada correctamente',
                'data' => $habilidad,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function updateUserSkill(Request $request, int $userId, int $id): JsonResponse
    {
        $habilidadUsuario = $this->habilidadService->findOwnedById($userId, $id);

        if (! $habilidadUsuario) {
            return response()->json([
                'message' => 'Habilidad del usuario no encontrada'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'habilidad_id' => ['nullable', 'integer', 'exists:habilidades,id_habilidad'],
            'nombre' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', 'in:tecnica,blanda'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'nivel' => ['sometimes', 'in:basico,intermedio,avanzado,experto'],
            'es_visible' => ['sometimes', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if ($request->nombre && !$request->tipo && !$request->habilidad_id) {
                $validator->errors()->add('tipo', 'Debe enviar el tipo cuando cambie a una habilidad nueva.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $habilidad = $this->habilidadService->updateUserSkill($habilidadUsuario, $validator->validated());

            return response()->json([
                'message' => 'Habilidad actualizada correctamente',
                'data' => $habilidad,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroyUserSkill(int $userId, int $id): JsonResponse
    {
        $habilidadUsuario = $this->habilidadService->findOwnedById($userId, $id);

        if (! $habilidadUsuario) {
            return response()->json([
                'message' => 'Habilidad del usuario no encontrada'
            ], 404);
        }

        $this->habilidadService->deleteUserSkill($habilidadUsuario);

        return response()->json([
            'message' => 'Habilidad eliminada correctamente',
        ]);
    }
}