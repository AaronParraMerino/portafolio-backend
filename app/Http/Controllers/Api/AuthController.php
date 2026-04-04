<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
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
        $data['password'] = Hash::make($data['password']);
        $data['rol'] = 'usuario';
        $data['estado'] = 'activo';
        $data['intentos_fallidos'] = 0;

        $usuario = Usuario::create($data);
        
        $token = $usuario->createToken('auth_token')->plainTextToken;
        return response()->json([
            'message' => 'Usuario registrado correctamente',
            'token' => $token,
            'data' => $usuario,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'correo' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $usuario = Usuario::where('correo', $data['correo'])->first();

        if (! $usuario || ! Hash::check($data['password'], $usuario->password)) {
            return response()->json([
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        if ($usuario->estado === 'bloqueado') {
            return response()->json([
                'message' => 'Usuario bloqueado',
            ], 403);
        }

        $token = $usuario->createToken('auth_token')->plainTextToken;
        
        return response()->json([
            'message' => 'Inicio de sesión correcto',
            'token' => $token,
            'data' => $usuario,
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->currentAccessToken()) {
            return response()->json([
                'message' => 'No hay sesión activa',
            ], 401);
        }

        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ], 200);
    }
}