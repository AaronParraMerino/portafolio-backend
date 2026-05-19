<?php

namespace App\Services\api\Auth;

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
}
