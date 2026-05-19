<?php

namespace App\Services\api;

use App\Models\Usuario;
use App\Models\Perfil;
use App\Services\api\Auth\DiscordOAuthService;
use App\Services\api\Auth\GithubOAuthService;
use App\Services\api\Auth\GitlabOAuthService;
use App\Services\api\Auth\GoogleOAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use App\Models\CuentaOauth;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        private readonly SeccionService $seccionService,
        private readonly GoogleOAuthService $googleOAuthService,
        private readonly GithubOAuthService $githubOAuthService,
        private readonly GitlabOAuthService $gitlabOAuthService,
        private readonly DiscordOAuthService $discordOAuthService,
    ) {
    }

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

        if ($usuario->estado === 'inactivo') {
            return [
                'status' => 'inactive',
                'correo' => $usuario->correo,
            ];
        }

        if ($usuario->estado === 'pausado') {
            return [
                'status' => 'paused',
            ];
        }

        $newToken = $usuario->createToken('auth_token');

        return [
            'status' => 'success',
            'usuario' => $usuario,
            'token' => $newToken->plainTextToken,
            'personal_access_token_id' => $newToken->accessToken->id,
        ];
    }

    public function logout(Usuario $usuario): void
    {
        $currentToken = $usuario->currentAccessToken();

        if (! $currentToken) {
            return;
        }

        $this->seccionService->clearAuthLinksByTokenIds([$currentToken->id]);

        $currentToken->delete();
        
    }

    public function loginWithGoogle(string $idToken): array
    {
        return $this->loginWithIdentity('google', $this->googleOAuthService->resolveIdentityByIdToken($idToken));
    }

    public function loginWithGithub(string $code): array
    {
        return $this->loginWithIdentity('github', $this->githubOAuthService->resolveIdentityByCode($code));
    }

    public function loginWithGitlab(string $code): array
    {
        return $this->loginWithIdentity('gitlab', $this->gitlabOAuthService->resolveIdentityByCode($code));
    }

    public function loginWithDiscord(string $code): array
    {
        return $this->loginWithIdentity('discord', $this->discordOAuthService->resolveIdentityByCode($code));
    }

    private function loginWithIdentity(string $provider, array $identity): array
    {
        if (($identity['status'] ?? 'invalid') !== 'success') {
            return ['status' => 'invalid'];
        }

        return $this->findOrCreateOAuthUser(
            $provider,
            $identity['provider_user_id'],
            $identity['email'],
            $identity['nombre'],
            $identity['foto_url'],
            $identity['oauth_meta'] ?? null,
        );
    }

    private function findOrCreateOAuthUser(
    string $provider,
    string $providerId,
    string $email,
    ?string $nombre,
    ?string $fotoUrl,
    ?array $oauthMeta = null
): array {
    $usuarioByEmail = Usuario::where('correo', $email)->first();

    $cuenta = CuentaOauth::where('provider', $provider)
                          ->where('provider_user_id', $providerId)
                          ->first();

        if ($cuenta) {
            if ($usuarioByEmail && $usuarioByEmail->id_usuario !== $cuenta->usuario_id) {
                return ['status' => 'already_linked'];
            }

            $usuario = $cuenta->usuario;

            if ($usuario->estado === 'inactivo') {
                return ['status' => 'inactive', 'correo' => $usuario->correo];
            }

            $cuenta->update($this->buildOauthAccountPayload($provider, $email, $nombre, $fotoUrl, $oauthMeta));
        } else {
            $usuario = $usuarioByEmail;

            if ($usuario) {
                if ($usuario->estado === 'inactivo') {
                    return ['status' => 'inactive', 'correo' => $usuario->correo];
                }

                $existingProviderLink = CuentaOauth::where('usuario_id', $usuario->id_usuario)
                ->where('provider', $provider)
                ->first();

            if ($existingProviderLink && $existingProviderLink->provider_user_id !== $providerId) {
                return ['status' => 'provider_conflict'];
            }

            $linkToken = (string) Str::uuid();
            Cache::put('oauth_link:' . $linkToken, [
                'provider'            => $provider,
                'provider_user_id'    => $providerId,
                'email'               => $email,
                'nombre'              => $nombre,
                'foto_url'            => $fotoUrl,
                'usuario_id'          => $usuario->id_usuario,
                'verification_method' => 'password',
            ], now()->addMinutes(10));

            return [
                'status'              => 'needs_link_confirmation',
                'link_token'          => $linkToken,
                'correo'              => $email,
                'provider'            => $provider,
                'verification_method' => 'password',
            ];
        } else {
            [$givenName, $familyName] = $this->splitProviderName($nombre, $provider);

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
                'usuario_id'  => $usuario->id_usuario,
                'foto_perfil' => $fotoUrl,
            ]);

            CuentaOauth::create([
                'usuario_id'       => $usuario->id_usuario,
                'provider'         => $provider,
                'provider_user_id' => $providerId,
                ...$this->buildOauthAccountPayload($provider, $email, $nombre, $fotoUrl, $oauthMeta),
            ]);
        }
    }

    if ($usuario->estado === 'bloqueado') {
        return ['status' => 'blocked'];
    }

    if ($usuario->estado === 'inactivo') {
        return ['status' => 'inactive', 'correo' => $usuario->correo];
    }

    if ($usuario->estado === 'pausado') {
        return ['status' => 'paused'];
    }

    $newToken = $usuario->createToken('auth_token');

    return [
        'status'                   => 'success',
        'token'                    => $newToken->plainTextToken,
        'personal_access_token_id' => $newToken->accessToken->id,
        'usuario'                  => $usuario,
        'foto_url'                 => $fotoUrl,
    ];
}

public function confirmOAuthLink(string $linkToken, ?string $password = null, ?string $code = null): array
{
    $data = Cache::get('oauth_link:' . $linkToken);

    if (! $data) {
        return ['status' => 'invalid'];
    }

    $usuario = Usuario::find($data['usuario_id']);

    if (! $usuario) {
        return ['status' => 'invalid'];
    }

    if ($usuario->estado === 'bloqueado') {
        return ['status' => 'blocked'];
    }

    // Verificar credencial según método
    $method = $data['verification_method'] ?? 'password';

    if ($method === 'password') {
        if (! $password || ! Hash::check($password, $usuario->password)) {
            return ['status' => 'wrong_credentials'];
        }
    } else {
        if (! $code || ! isset($data['code_hash']) || ! Hash::check($code, $data['code_hash'])) {
            return ['status' => 'wrong_credentials'];
        }
    }

    // Credencial correcta → consumir caché y crear vínculo
    Cache::forget('oauth_link:' . $linkToken);

    $alreadyLinked = CuentaOauth::where('usuario_id', $usuario->id_usuario)
        ->where('provider', $data['provider'])
        ->exists();

    if (! $alreadyLinked) {
        CuentaOauth::create([
            'usuario_id'       => $usuario->id_usuario,
            'provider'         => $data['provider'],
            'provider_user_id' => $data['provider_user_id'],
            ...$this->buildOauthAccountPayload(
                $data['provider'],
                $data['email'],
                $data['nombre'],
                $data['foto_url'],
                $data['oauth_meta'] ?? null,
            ),
        ]);
    }

    $newToken = $usuario->createToken('auth_token');

    return [
        'status'                   => 'success',
        'token'                    => $newToken->plainTextToken,
        'personal_access_token_id' => $newToken->accessToken->id,
        'usuario'                  => $usuario,
        'foto_url'                 => $data['foto_url'],
    ];
}

private function splitProviderName(?string $name, string $provider = ''): array
{
    if (! $name) {
        return ['Usuario', ucfirst($provider)];
    }
    $parts = preg_split('/\s+/', trim($name), 2);
    return [$parts[0], $parts[1] ?? ucfirst($provider)];
}

public function getLinkedProviders(int $usuarioId): array
{
    $providers = ['google', 'github', 'gitlab', 'discord'];

    $links = CuentaOauth::where('usuario_id', $usuarioId)
        ->whereIn('provider', $providers)
        ->get()
        ->keyBy('provider');

    $result = [];

    foreach ($providers as $provider) {
        $link = $links->get($provider);

        $detail = 'No vinculado';
        if ($link) {
            $detail = $link->email ?: ($link->nombre ?: 'Conectado');
        }

        $result[] = [
            'provider' => $provider,
            'connected' => (bool) $link,
            'detail' => $detail,
        ];
    }

    return $result;
}

public function linkOAuthAccountToUser(int $usuarioId, string $provider, string $code): array
{
    $usuario = Usuario::find($usuarioId);

    if (! $usuario) {
        return ['status' => 'invalid_user'];
    }

    if ($usuario->estado === 'bloqueado') {
        return ['status' => 'blocked'];
    }

    $identity = $this->resolveOAuthIdentityByCode($provider, $code);

    if (($identity['status'] ?? 'invalid') !== 'success') {
        return ['status' => 'invalid'];
    }

    $existingByProviderUser = CuentaOauth::where('provider', $provider)
        ->where('provider_user_id', $identity['provider_user_id'])
        ->first();

    if ($existingByProviderUser && $existingByProviderUser->usuario_id !== $usuario->id_usuario) {
        return ['status' => 'already_linked'];
    }

    $existingForUserProvider = CuentaOauth::where('usuario_id', $usuario->id_usuario)
        ->where('provider', $provider)
        ->first();

    if ($existingForUserProvider && $existingForUserProvider->provider_user_id !== $identity['provider_user_id']) {
        return ['status' => 'provider_conflict'];
    }

    if ($existingForUserProvider) {
        $existingForUserProvider->update([
            ...$this->buildOauthAccountPayload(
                $provider,
                $identity['email'],
                $identity['nombre'],
                $identity['foto_url'],
                $identity['oauth_meta'] ?? null,
            ),
        ]);

        return [
            'status' => 'already_connected',
            'provider' => $provider,
            'detail' => $identity['email'] ?: ($identity['nombre'] ?: 'Conectado'),
        ];
    }

    CuentaOauth::create([
        'usuario_id' => $usuario->id_usuario,
        'provider' => $provider,
        'provider_user_id' => $identity['provider_user_id'],
        ...$this->buildOauthAccountPayload(
            $provider,
            $identity['email'],
            $identity['nombre'],
            $identity['foto_url'],
            $identity['oauth_meta'] ?? null,
        ),
    ]);

    return [
        'status' => 'success',
        'provider' => $provider,
        'detail' => $identity['email'] ?: ($identity['nombre'] ?: 'Conectado'),
    ];
}

public function unlinkOAuthAccountFromUser(int $usuarioId, string $provider): array
{
    $link = CuentaOauth::where('usuario_id', $usuarioId)
        ->where('provider', $provider)
        ->first();

    if (! $link) {
        return ['status' => 'not_found'];
    }

    $total = CuentaOauth::where('usuario_id', $usuarioId)->count();
    if ($total <= 1) {
        return ['status' => 'last_provider'];
    }

    $link->delete();

    return ['status' => 'success'];
}

private function resolveOAuthIdentityByCode(string $provider, string $code): array
{
    return match ($provider) {
        'google' => $this->googleOAuthService->resolveIdentityByCode($code),
        'github' => $this->githubOAuthService->resolveIdentityByCode($code),
        'gitlab' => $this->gitlabOAuthService->resolveIdentityByCode($code),
        'discord' => $this->discordOAuthService->resolveIdentityByCode($code),
        default => ['status' => 'invalid'],
    };
}
private function buildOauthAccountPayload(
    string $provider,
    string $email,
    ?string $nombre,
    ?string $fotoUrl,
    ?array $oauthMeta = null,
): array {
    $payload = [
        'email' => $email,
        'nombre' => $nombre,
        'foto_url' => $fotoUrl,
    ];

    if (! in_array($provider, ['github', 'gitlab'], true) || ! is_array($oauthMeta)) {
        return $payload;
    }

    return array_merge($payload, [
        'access_token' => $oauthMeta['access_token'] ?? null,
        'refresh_token' => $oauthMeta['refresh_token'] ?? null,
        'token_scopes' => $oauthMeta['token_scopes'] ?? null,
        'token_expires_at' => $oauthMeta['token_expires_at'] ?? null,
        'token_updated_at' => $oauthMeta['token_updated_at'] ?? now(),
    ]);
}

}
