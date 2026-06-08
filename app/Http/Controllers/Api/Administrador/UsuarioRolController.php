<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Services\api\Administrador\AdminUsuarioRolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UsuarioRolController extends Controller
{
    public function __construct(private readonly AdminUsuarioRolService $adminUsuarioRolService) {}

    public function update(Request $request, int $id): JsonResponse
    {
        if ($request->user()?->rol !== 'admin') {
            return response()->json([
                'message' => 'No tienes permiso para acceder a la administracion de usuarios.',
            ], 403);
        }

        $data = $request->validate([
            'rol' => ['required', 'string', Rule::in(['usuario', 'publicante'])],
            'razon' => ['required', 'string', 'min:10', 'max:1000'],
            'canales' => ['sometimes', 'array', 'min:1'],
            'canales.*' => ['required', 'distinct', Rule::in(['inapp', 'email'])],
        ]);

        $result = $this->adminUsuarioRolService->update(
            (int) $request->user()->id_usuario,
            $id,
            $data['rol'],
            trim((string) $data['razon']),
            $data['canales'] ?? ['inapp', 'email'],
        );

        return response()->json($result['body'], $result['status']);
    }
}
