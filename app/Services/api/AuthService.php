<?php

namespace App\Services\api;

use App\Models\Usuario;
use App\Models\Perfil;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use App\Models\CuentaOauth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        private readonly SeccionService $seccionService,
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
    $fotoUrl = $this->uploadGoogleImageToSupabase($payload['picture'] ?? null);

    return $this->findOrCreateOAuthUser('google', (string) $googleId, $email, $nombre, $fotoUrl);
}

private function splitGoogleName(?string $name): array
{
    if (! $name) {
        return ['Usuario', 'Google'];
    }
    $parts = preg_split('/\s+/', trim($name), 2);
    return [$parts[0], $parts[1] ?? 'Google'];
}

private function uploadGoogleImageToSupabase(?string $imageUrl): ?string
{
    if (! $imageUrl) {
        return null;
    }

    $bucket = env('SUPABASE_BUCKET');
    $urlBase = env('SUPABASE_URL');
    $key = env('SUPABASE_KEY');

    if (! $bucket || ! $urlBase || ! $key) {
        return $imageUrl;
    }

    try {
        $imageResponse = Http::timeout(10)->get($imageUrl);
        if (! $imageResponse->ok()) {
            return $imageUrl;
        }

        $contentType = $imageResponse->header('Content-Type') ?: 'image/jpeg';
        $extension = 'jpg';
        if (str_contains($contentType, '/')) {
            $extension = explode('/', $contentType)[1] ?: 'jpg';
            $extension = strtolower(explode(';', $extension)[0]);
        }

        $nombreArchivo = 'profile/' . Str::uuid() . '.' . $extension;
        $fileContent = $imageResponse->body();

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $urlBase . '/storage/v1/object/' . $bucket . '/' . $nombreArchivo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $fileContent,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: ' . $contentType,
            ],
        ]);

        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $imageUrl;
        }

        return $urlBase . '/storage/v1/object/public/' . $bucket . '/' . $nombreArchivo;
    } catch (\Throwable $e) {
        return $imageUrl;
    }
}

// ─────────────────────────────────────────────────────────────
//  OAuth — Authorization Code flow (GitHub, GitLab, Discord)
// ─────────────────────────────────────────────────────────────

public function loginWithGithub(string $code): array
{
    $tokenResponse = Http::timeout(10)
        ->withHeaders(['Accept' => 'application/json'])
        ->post('https://github.com/login/oauth/access_token', [
            'client_id'     => config('services.github.client_id'),
            'client_secret' => config('services.github.client_secret'),
            'code'          => $code,
            'redirect_uri'  => config('services.github.redirect'),
        ]);

    if (! $tokenResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $accessToken = $tokenResponse->json()['access_token'] ?? null;
    if (! $accessToken) {
        return ['status' => 'invalid'];
    }

    $oauthMeta = [
        'access_token' => $accessToken,
        'token_scopes' => $tokenResponse->json()['scope'] ?? null,
        'token_updated_at' => now(),
    ];

    $userResponse = Http::timeout(10)
        ->withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'User-Agent'    => 'portafolio-app',
        ])
        ->get('https://api.github.com/user');

    if (! $userResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $userData = $userResponse->json();
    $email    = $userData['email'] ?? null;

    // Email privado → obtener del endpoint de emails
    if (! $email) {
        $emailsResponse = Http::timeout(10)
            ->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'User-Agent'    => 'portafolio-app',
            ])
            ->get('https://api.github.com/user/emails');

        if ($emailsResponse->ok()) {
            $emails  = collect($emailsResponse->json());
            $primary = $emails->firstWhere('primary', true);
            $email   = $primary['email'] ?? ($emails->first()['email'] ?? null);
        }
    }

    if (! $email) {
        return ['status' => 'invalid'];
    }

    $providerId = (string) ($userData['id'] ?? '');
    $nombre     = $userData['name'] ?? $userData['login'] ?? null;
    $fotoUrl    = $userData['avatar_url'] ?? null;

    return $this->findOrCreateOAuthUser('github', $providerId, strtolower(trim($email)), $nombre, $fotoUrl, $oauthMeta);
}

public function loginWithGitlab(string $code): array
{
    $tokenResponse = Http::timeout(10)
        ->asForm()
        ->post('https://gitlab.com/oauth/token', [
            'client_id'     => config('services.gitlab.client_id'),
            'client_secret' => config('services.gitlab.client_secret'),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => config('services.gitlab.redirect'),
        ]);

    if (! $tokenResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $accessToken = $tokenResponse->json()['access_token'] ?? null;
    if (! $accessToken) {
        return ['status' => 'invalid'];
    }

    $userResponse = Http::timeout(10)
        ->withHeaders(['Authorization' => "Bearer {$accessToken}"])
        ->get('https://gitlab.com/api/v4/user');

    if (! $userResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $userData   = $userResponse->json();
    $email      = strtolower(trim($userData['email'] ?? ''));
    $providerId = (string) ($userData['id'] ?? '');
    $nombre     = $userData['name'] ?? null;
    $fotoUrl    = $userData['avatar_url'] ?? null;

    if (! $email || ! $providerId) {
        return ['status' => 'invalid'];
    }

    return $this->findOrCreateOAuthUser('gitlab', $providerId, $email, $nombre, $fotoUrl);
}

public function loginWithDiscord(string $code): array
{
    $tokenResponse = Http::timeout(10)
        ->asForm()
        ->post('https://discord.com/api/oauth2/token', [
            'client_id'     => config('services.discord.client_id'),
            'client_secret' => config('services.discord.client_secret'),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => config('services.discord.redirect'),
        ]);

    if (! $tokenResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $accessToken = $tokenResponse->json()['access_token'] ?? null;
    if (! $accessToken) {
        return ['status' => 'invalid'];
    }

    $userResponse = Http::timeout(10)
        ->withHeaders(['Authorization' => "Bearer {$accessToken}"])
        ->get('https://discord.com/api/users/@me');

    if (! $userResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $userData   = $userResponse->json();
    $email      = strtolower(trim($userData['email'] ?? ''));
    $verified   = $userData['verified'] ?? false;
    $providerId = (string) ($userData['id'] ?? '');
    $username   = $userData['username'] ?? null;
    $avatar     = $userData['avatar'] ?? null;
    $fotoUrl    = $avatar
        ? "https://cdn.discordapp.com/avatars/{$providerId}/{$avatar}.png"
        : null;

    if (! $email || ! $verified || ! $providerId) {
        return ['status' => 'invalid'];
    }

    return $this->findOrCreateOAuthUser('discord', $providerId, $email, $username, $fotoUrl);
}

// ─── helper compartido ───────────────────────────────────────

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
        'google' => $this->resolveGoogleIdentityByCode($code),
        'github' => $this->resolveGithubIdentityByCode($code),
        'gitlab' => $this->resolveGitlabIdentityByCode($code),
        'discord' => $this->resolveDiscordIdentityByCode($code),
        default => ['status' => 'invalid'],
    };
}

private function resolveGoogleIdentityByCode(string $code): array
{
    $tokenResponse = Http::timeout(10)
        ->asForm()
        ->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

    if (! $tokenResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $accessToken = $tokenResponse->json()['access_token'] ?? null;
    $idToken = $tokenResponse->json()['id_token'] ?? null;

    if (! $accessToken || ! $idToken) {
        return ['status' => 'invalid'];
    }

    $tokenInfo = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
        'id_token' => $idToken,
    ]);

    if (! $tokenInfo->ok()) {
        return ['status' => 'invalid'];
    }

    $payload = $tokenInfo->json();
    $clientId = config('services.google.client_id');
    $providerId = (string) ($payload['sub'] ?? '');
    $email = strtolower(trim($payload['email'] ?? ''));
    $verified = in_array($payload['email_verified'] ?? false, [true, 'true'], true);
    $name = $payload['name'] ?? null;

    if (! $clientId || ($payload['aud'] ?? null) !== $clientId || ! $providerId || ! $email || ! $verified) {
        return ['status' => 'invalid'];
    }

    $userInfo = Http::timeout(10)
        ->withToken($accessToken)
        ->get('https://openidconnect.googleapis.com/v1/userinfo');

    if ($userInfo->ok()) {
        $name = $userInfo->json()['name'] ?? $name;
    }

    return [
        'status' => 'success',
        'provider_user_id' => $providerId,
        'email' => $email,
        'nombre' => $name,
        'foto_url' => $this->uploadGoogleImageToSupabase($payload['picture'] ?? null),
    ];
}

private function resolveGithubIdentityByCode(string $code): array
{
    $tokenResponse = Http::timeout(10)
        ->withHeaders(['Accept' => 'application/json'])
        ->post('https://github.com/login/oauth/access_token', [
            'client_id' => config('services.github.client_id'),
            'client_secret' => config('services.github.client_secret'),
            'code' => $code,
            'redirect_uri' => config('services.github.redirect'),
        ]);

    if (! $tokenResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $accessToken = $tokenResponse->json()['access_token'] ?? null;
    if (! $accessToken) {
        return ['status' => 'invalid'];
    }

    $oauthMeta = [
        'access_token' => $accessToken,
        'token_scopes' => $tokenResponse->json()['scope'] ?? null,
        'token_updated_at' => now(),
    ];

    $userResponse = Http::timeout(10)
        ->withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'User-Agent' => 'portafolio-app',
        ])
        ->get('https://api.github.com/user');

    if (! $userResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $userData = $userResponse->json();
    $email = $userData['email'] ?? null;

    if (! $email) {
        $emailsResponse = Http::timeout(10)
            ->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'User-Agent' => 'portafolio-app',
            ])
            ->get('https://api.github.com/user/emails');

        if ($emailsResponse->ok()) {
            $emails = collect($emailsResponse->json());
            $primary = $emails->firstWhere('primary', true);
            $email = $primary['email'] ?? ($emails->first()['email'] ?? null);
        }
    }

    $providerId = (string) ($userData['id'] ?? '');
    if (! $email || ! $providerId) {
        return ['status' => 'invalid'];
    }

    return [
        'status' => 'success',
        'provider_user_id' => $providerId,
        'email' => strtolower(trim($email)),
        'nombre' => $userData['name'] ?? $userData['login'] ?? null,
        'foto_url' => $userData['avatar_url'] ?? null,
        'oauth_meta' => $oauthMeta,
    ];
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

    if ($provider !== 'github' || ! is_array($oauthMeta)) {
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

private function resolveGitlabIdentityByCode(string $code): array
{
    $tokenResponse = Http::timeout(10)
        ->asForm()
        ->post('https://gitlab.com/oauth/token', [
            'client_id' => config('services.gitlab.client_id'),
            'client_secret' => config('services.gitlab.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.gitlab.redirect'),
        ]);

    if (! $tokenResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $accessToken = $tokenResponse->json()['access_token'] ?? null;
    if (! $accessToken) {
        return ['status' => 'invalid'];
    }

    $userResponse = Http::timeout(10)
        ->withHeaders(['Authorization' => "Bearer {$accessToken}"])
        ->get('https://gitlab.com/api/v4/user');

    if (! $userResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $userData = $userResponse->json();
    $providerId = (string) ($userData['id'] ?? '');
    $email = strtolower(trim($userData['email'] ?? ''));

    if (! $providerId || ! $email) {
        return ['status' => 'invalid'];
    }

    return [
        'status' => 'success',
        'provider_user_id' => $providerId,
        'email' => $email,
        'nombre' => $userData['name'] ?? null,
        'foto_url' => $userData['avatar_url'] ?? null,
    ];
}

private function resolveDiscordIdentityByCode(string $code): array
{
    $tokenResponse = Http::timeout(10)
        ->asForm()
        ->post('https://discord.com/api/oauth2/token', [
            'client_id' => config('services.discord.client_id'),
            'client_secret' => config('services.discord.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.discord.redirect'),
        ]);

    if (! $tokenResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $accessToken = $tokenResponse->json()['access_token'] ?? null;
    if (! $accessToken) {
        return ['status' => 'invalid'];
    }

    $userResponse = Http::timeout(10)
        ->withHeaders(['Authorization' => "Bearer {$accessToken}"])
        ->get('https://discord.com/api/users/@me');

    if (! $userResponse->ok()) {
        return ['status' => 'invalid'];
    }

    $userData = $userResponse->json();
    $providerId = (string) ($userData['id'] ?? '');
    $email = strtolower(trim($userData['email'] ?? ''));
    $verified = (bool) ($userData['verified'] ?? false);
    $avatar = $userData['avatar'] ?? null;

    if (! $providerId || ! $email || ! $verified) {
        return ['status' => 'invalid'];
    }

    return [
        'status' => 'success',
        'provider_user_id' => $providerId,
        'email' => $email,
        'nombre' => $userData['username'] ?? null,
        'foto_url' => $avatar ? "https://cdn.discordapp.com/avatars/{$providerId}/{$avatar}.png" : null,
    ];
}
}
