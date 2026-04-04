<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UsuarioController extends Controller
{
    public function __construct(private readonly UsuarioService $usuarioService)
    {
    }

    public function index(): JsonResponse
    {
        $usuarios = $this->usuarioService->getAll();

        return response()->json($usuarios);
    }

    public function show($id): JsonResponse
    {
        $usuario = $this->usuarioService->findById((int) $id);

        if (! $usuario) {
            return response()->json([
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        return response()->json($usuario);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'correo' => ['required', 'email', 'max:255', 'unique:usuarios,correo'],
            'password' => ['required', 'string', 'min:8'],
            'telefono' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $usuario = $this->usuarioService->create($data);

        return response()->json([
            'message' => 'Usuario creado correctamente',
            'data' => $usuario,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $usuario = $this->usuarioService->findById((int) $id);

        if (! $usuario) {
            return response()->json([
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => ['sometimes', 'string', 'max:255'],
            'apellido' => ['sometimes', 'string', 'max:255'],
            'correo' => ['sometimes', 'email', 'max:255', 'unique:usuarios,correo,' . $id . ',id_usuario'],
            'password' => ['sometimes', 'string', 'min:8'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'rol' => ['sometimes', 'in:admin,usuario'],
            'estado' => ['sometimes', 'in:activo,bloqueado'],
            'idioma_preferido' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $usuario = $this->usuarioService->update($usuario, $data);

        return response()->json([
            'message' => 'Usuario actualizado correctamente',
            'data' => $usuario,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $usuario = $this->usuarioService->findById((int) $id);

        if (! $usuario) {
            return response()->json([
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $this->usuarioService->delete($usuario);

        return response()->json([
            'message' => 'Usuario eliminado correctamente',
        ]);
    }
}