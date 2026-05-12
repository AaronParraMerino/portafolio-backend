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
    ) {
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
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->authService->register($validator->validated());

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
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $result = $this->authService->attemptLogin($data);

        if ($result['status'] === 'invalid') {
            return response()->json(['message' => 'Credenciales incorrectas'], 401);
        }

        if ($result['status'] === 'blocked') {
            return response()->json(['message' => 'Usuario bloqueado'], 403);
        }

        if ($result['status'] === 'inactive') {
            return response()->json([
                'status' => 'inactive_account',
                'message' => 'Esta cuenta fue desactivada. Puedes restablecerla con un codigo enviado a tu correo.',
                'correo' => $result['correo'] ?? $data['correo'],
            ], 403);
        }

        if ($result['status'] === 'paused') {
            return response()->json(['message' => 'Usuario pausado'], 403);
        }

        $this->linkSessionToken($request, $data['session_token'] ?? null, $result);

        return response()->json([
            'message' => 'Inicio de sesion correcto',
            'token' => $result['token'],
            'data' => $result['usuario'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->currentAccessToken()) {
            return response()->json(['message' => 'No hay sesion activa'], 401);
        }

        $this->authService->logout($user);

        return response()->json(['message' => 'Sesion cerrada correctamente']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()]);
    }

    public function linkedProviders(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No hay sesion activa'], 401);
        }

        return response()->json([
            'data' => $this->authService->getLinkedProviders($user->id_usuario),
        ]);
    }

    public function confirmOAuthLink(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'link_token' => ['required', 'string'],
            'password' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'size:6'],
            'session_token' => ['nullable', 'string', 'size:64'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $result = $this->authService->confirmOAuthLink(
            $data['link_token'],
            $data['password'] ?? null,
            $data['code'] ?? null,
        );

        if ($result['status'] === 'invalid') {
            return response()->json(['message' => 'El enlace de confirmacion expiro o no es valido'], 401);
        }

        if ($result['status'] === 'blocked') {
            return response()->json(['message' => 'Usuario bloqueado'], 403);
        }

        if ($result['status'] === 'wrong_credentials') {
            return response()->json(['status' => 'wrong_credentials', 'message' => 'Credenciales incorrectas'], 401);
        }

        $this->linkSessionToken($request, $data['session_token'] ?? null, $result);

        return response()->json([
            'message' => 'Cuenta vinculada e inicio de sesion correcto',
            'token' => $result['token'],
            'data' => $result['usuario'],
        ]);
    }

    private function linkSessionToken(Request $request, ?string $sessionToken, array $authResult): void
    {
        $sessionToken ??= $request->cookie('foliToken');

        if (! $sessionToken || ! isset($authResult['personal_access_token_id'])) {
            return;
        }

        $this->seccionService->linkAuthBySessionToken(
            $sessionToken,
            $authResult['usuario']->id_usuario,
            (int) $authResult['personal_access_token_id'],
        );
    }
}
