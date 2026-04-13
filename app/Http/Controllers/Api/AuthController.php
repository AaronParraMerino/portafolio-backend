<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\AuthService;
use App\Services\api\SeccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly SeccionService $seccionService,
    )
    {
    }

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
        $result = $this->authService->register($data);

        return response()->json([
            'message' => 'Usuario registrado correctamente',
            'token' => $result['token'],
            'data' => $result['usuario'],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'correo' => ['required', 'email'],
            'password' => ['required', 'string'],
            'session_token' => ['nullable', 'string', 'size:64'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $result = $this->authService->attemptLogin($data);

        if ($result['status'] === 'invalid') {
            return response()->json([
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        if ($result['status'] === 'blocked') {
            return response()->json([
                'message' => 'Usuario bloqueado',
            ], 403);
        }
        
        $sessionToken = $data['session_token'] ?? $request->cookie('foliToken');

        if ($sessionToken && isset($result['personal_access_token_id'])) {
            $this->seccionService->linkAuthBySessionToken(
                $sessionToken,
                $result['usuario']->id_usuario,
                (int) $result['personal_access_token_id']
            );
        }

        return response()->json([
            'message' => 'Inicio de sesión correcto',
            'token' => $result['token'],
            'data' => $result['usuario'],
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

        $this->authService->logout($user);

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ], 200);
    }

    public function googleAuth(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'id_token' => ['required', 'string'],
        'session_token' => ['nullable', 'string', 'size:64'],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Datos inválidos',
            'errors'  => $validator->errors(),
        ], 422);
    }

    $data = $validator->validated();
    $result = $this->authService->loginWithGoogle($data['id_token']);

    if ($result['status'] === 'invalid') {
        return response()->json(['message' => 'No se pudo validar la cuenta de Google'], 401);
    }

    if ($result['status'] === 'blocked') {
        return response()->json(['message' => 'Usuario bloqueado'], 403);
    }

    $sessionToken = $data['session_token'] ?? $request->cookie('foliToken');

    if ($sessionToken && isset($result['personal_access_token_id'])) {
        $this->seccionService->linkAuthBySessionToken(
            $sessionToken,
            $result['usuario']->id_usuario,
            (int) $result['personal_access_token_id']
        );
    }

    return response()->json([
        'message'  => 'Autenticación con Google correcta',
        'token'    => $result['token'],
        'data'     => $result['usuario'],
        'foto_url' => $result['foto_url'],
    ], 200);
}
}