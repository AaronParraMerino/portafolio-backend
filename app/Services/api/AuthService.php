<?php

namespace App\Services\api;

use App\Models\Usuario;
use App\Models\Perfil;
use Illuminate\Support\Facades\Hash;
use App\Models\CuentaOauth;
use App\Models\Perfil;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

    public function loginWithGoogle(string $idToken): array
{
    // Verificar token con Google
    $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
        'id_token' => $idToken,
    ]);

    if (! $response->ok()) {
        return ['status' => 'invalid'];
    }

    $payload   = $response->json();
    $clientId  = config('services.google.client_id');
    $googleId  = $payload['sub'] ?? null;
    $email     = strtolower(trim($payload['email'] ?? ''));
    $verified  = in_array($payload['email_verified'] ?? false, [true, 'true'], true);

    if (! $clientId || ($payload['aud'] ?? null) !== $clientId || ! $googleId || ! $email || ! $verified) {
        return ['status' => 'invalid'];
    }

    $nombre  = $payload['name'] ?? null;
    $fotoUrl = $payload['picture'] ?? null;

    // ¿Ya existe la cuenta OAuth vinculada?
    $cuenta = CuentaOauth::where('provider', 'google')
                          ->where('provider_user_id', $googleId)
                          ->first();

    if ($cuenta) {
        $usuario = $cuenta->usuario;
    } else {
        // ¿Existe usuario por correo?
        $usuario = Usuario::where('correo', $email)->first();

        if ($usuario) {
            // Solo vincular, no tocar sus datos existentes
            CuentaOauth::create([
                'usuario_id'       => $usuario->id_usuario,
                'provider'         => 'google',
                'provider_user_id' => $googleId,
                'email'            => $email,
                'nombre'           => $nombre,
                'foto_url'         => $fotoUrl,
            ]);
        } else {
            // Usuario nuevo: crear en usuarios, perfiles y cuentas_oauth
            [$givenName, $familyName] = $this->splitGoogleName($nombre);

            $usuario = Usuario::create([
                'nombre'            => $givenName,
                'apellido'          => $familyName,
                'correo'            => $email,
                'password'          => Hash::make(Str::random(40)),
                'rol'               => 'usuario',
                'estado'            => 'activo',
                'intentos_fallidos' => 0,
            ]);

            Perfil::create([
                'usuario_id'         => $usuario->id_usuario,
                'foto_perfil'        => $fotoUrl,
                'fecha_modificacion' => now(),
            ]);

            CuentaOauth::create([
                'usuario_id'       => $usuario->id_usuario,
                'provider'         => 'google',
                'provider_user_id' => $googleId,
                'email'            => $email,
                'nombre'           => $nombre,
                'foto_url'         => $fotoUrl,
            ]);
        }
    }

    if ($usuario->estado === 'bloqueado') {
        return ['status' => 'blocked'];
    }

    return [
        'status'   => 'success',
        'token'    => $usuario->createToken('auth_token')->plainTextToken,
        'usuario'  => $usuario,
        'foto_url' => $fotoUrl,
    ];
}

private function splitGoogleName(?string $name): array
{
    if (! $name) {
        return ['Usuario', 'Google'];
    }
    $parts = preg_split('/\s+/', trim($name), 2);
    return [$parts[0], $parts[1] ?? 'Google'];
}
}