<?php

namespace App\Services\api;

use App\Models\Usuario;
use App\Models\Perfil;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function register(array $data): array
    {
        $data['password'] = Hash::make($data['password']);
        $data['rol'] = 'usuario';
        $data['estado'] = 'activo';
        $data['intentos_fallidos'] = 0;

        $usuario = Usuario::create($data);
        $token = $usuario->createToken('auth_token')->plainTextToken;

        //
        Perfil::create([
        'usuario_id' => $usuario->id_usuario,
        ]);
        //

        return [
            'usuario' => $usuario,
            'token' => $token,
        ];
    }

    public function attemptLogin(array $credentials): array
    {
        $usuario = Usuario::where('correo', $credentials['correo'])->first();

        if (! $usuario || ! Hash::check($credentials['password'], $usuario->password)) {
            return [
                'status' => 'invalid',
            ];
        }

        if ($usuario->estado === 'bloqueado') {
            return [
                'status' => 'blocked',
            ];
        }

        return [
            'status' => 'success',
            'usuario' => $usuario,
            'token' => $usuario->createToken('auth_token')->plainTextToken,
        ];
    }

    public function logout(Usuario $usuario): void
    {
        $usuario->currentAccessToken()?->delete();
    }
}