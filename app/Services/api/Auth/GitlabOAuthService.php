<?php

namespace App\Services\api\Auth;

use App\Models\CuentaOauth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GitlabOAuthService
{
    public function resolveIdentityByCode(string $code): array
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

        $tokenPayload = $tokenResponse->json();
        $accessToken = $tokenPayload['access_token'] ?? null;
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
            'oauth_meta' => [
                'access_token' => $accessToken,
                'refresh_token' => $tokenPayload['refresh_token'] ?? null,
                'token_scopes' => $tokenPayload['scope'] ?? null,
                'token_expires_at' => isset($tokenPayload['expires_in'])
                    ? now()->addSeconds((int) $tokenPayload['expires_in'])
                    : null,
                'token_updated_at' => now(),
            ],
        ];
    }

    public function refreshAccessToken(CuentaOauth $cuentaGitlab, ?string $staleAccessToken = null): array
    {
        return DB::transaction(function () use ($cuentaGitlab, $staleAccessToken): array {
            $lockedAccount = CuentaOauth::whereKey($cuentaGitlab->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedAccount) {
                return [
                    'status' => 'not_linked',
                    'message' => 'La vinculacion GitLab ya no existe.',
                ];
            }

            if (
                $staleAccessToken !== null
                && $lockedAccount->access_token
                && ! hash_equals($staleAccessToken, $lockedAccount->access_token)
            ) {
                return [
                    'status' => 'success',
                    'access_token' => $lockedAccount->access_token,
                ];
            }

            if (! $lockedAccount->refresh_token) {
                return [
                    'status' => 'invalid_token',
                    'message' => 'La vinculacion GitLab vencio y no puede renovarse automaticamente. Vuelve a vincular la cuenta.',
                ];
            }

            try {
                $tokenResponse = Http::timeout(10)
                    ->asForm()
                    ->post('https://gitlab.com/oauth/token', [
                        'client_id' => config('services.gitlab.client_id'),
                        'client_secret' => config('services.gitlab.client_secret'),
                        'refresh_token' => $lockedAccount->refresh_token,
                        'grant_type' => 'refresh_token',
                        'redirect_uri' => config('services.gitlab.redirect'),
                    ]);
            } catch (ConnectionException) {
                return [
                    'status' => 'provider_error',
                    'message' => 'No se pudo conectar con GitLab para renovar la vinculacion. Intenta nuevamente.',
                ];
            }

            if (in_array($tokenResponse->status(), [400, 401], true)) {
                return [
                    'status' => 'invalid_token',
                    'message' => 'No se pudo renovar la vinculacion GitLab. Vuelve a vincular la cuenta.',
                ];
            }

            if (! $tokenResponse->ok()) {
                return [
                    'status' => 'provider_error',
                    'message' => 'GitLab no esta disponible para renovar la vinculacion. Intenta nuevamente.',
                ];
            }

            $tokenPayload = $tokenResponse->json();
            $accessToken = $tokenPayload['access_token'] ?? null;
            $refreshToken = $tokenPayload['refresh_token'] ?? null;

            if (! $accessToken || ! $refreshToken) {
                return [
                    'status' => 'provider_error',
                    'message' => 'GitLab no devolvio tokens validos al renovar la vinculacion. Intenta nuevamente.',
                ];
            }

            $lockedAccount->update([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_scopes' => $tokenPayload['scope'] ?? $lockedAccount->token_scopes,
                'token_expires_at' => isset($tokenPayload['expires_in'])
                    ? now()->addSeconds((int) $tokenPayload['expires_in'])
                    : null,
                'token_updated_at' => now(),
            ]);

            return [
                'status' => 'success',
                'access_token' => $accessToken,
            ];
        });
    }
}
