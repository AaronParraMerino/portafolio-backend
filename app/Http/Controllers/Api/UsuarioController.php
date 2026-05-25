<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\api\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            'rol' => ['sometimes', 'in:admin,usuario,otro'],
            'estado' => ['sometimes', 'in:activo,bloqueado,inactivo,pausado'],
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
        $authUser = request()->user();

        if (! $usuario) {
            return response()->json([
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        if (! $authUser || ((int) $authUser->id_usuario !== (int) $usuario->id_usuario && $authUser->rol !== 'admin')) {
            return response()->json([
                'message' => 'No tienes permiso para eliminar esta cuenta'
            ], 403);
        }

        $validator = Validator::make(request()->all(), [
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debes confirmar tu contrasena',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! Hash::check($validator->validated()['password'], $authUser->password)) {
            return response()->json([
                'message' => 'La contrasena no es correcta'
            ], 422);
        }

        $this->usuarioService->delete($usuario);

        return response()->json([
            'message' => 'Cuenta desactivada correctamente',
        ]);
    }

    public function cambiarPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password_actual'      => ['required', 'string'],
            'password_nueva'       => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        /** @var \App\Models\Usuario $usuario */
        $usuario = $request->user();

        if (! Hash::check($request->password_actual, $usuario->password)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 422);
        }

        $usuario->password = Hash::make($request->password_nueva);
        $usuario->save();

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }
}
