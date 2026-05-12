<?php

namespace App\Http\Controllers\Api\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GoogleAuthController extends ProviderOAuthController
{
    protected function provider(): string
    {
        return 'google';
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_token' => ['required', 'string'],
            'session_token' => ['nullable', 'string', 'size:64'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
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

        if ($result['status'] === 'inactive') {
            return response()->json([
                'status' => 'inactive_account',
                'message' => 'Esta cuenta fue desactivada. Puedes restablecerla con un codigo enviado a tu correo.',
                'correo' => $result['correo'] ?? null,
            ], 403);
        }

        if ($result['status'] === 'paused') {
            return response()->json(['message' => 'Usuario pausado'], 403);
        }

        if ($result['status'] === 'provider_conflict') {
            return response()->json(['message' => 'Ya existe una cuenta de Google distinta vinculada a este correo'], 409);
        }

        if ($result['status'] === 'already_linked') {
            return response()->json(['message' => 'Esta cuenta OAuth ya esta vinculada a otra cuenta'], 409);
        }

        if ($result['status'] === 'needs_link_confirmation') {
            return response()->json([
                'status' => 'needs_link_confirmation',
                'link_token' => $result['link_token'],
                'correo' => $result['correo'],
                'provider' => $result['provider'],
                'verification_method' => $result['verification_method'],
                'message' => 'Ya existe una cuenta con este correo.',
            ]);
        }

        $this->linkSessionToken($request, $data['session_token'] ?? null, $result);

        return response()->json([
            'message' => 'Autenticacion con Google correcta',
            'token' => $result['token'],
            'data' => $result['usuario'],
            'foto_url' => $result['foto_url'],
        ]);
    }
}
