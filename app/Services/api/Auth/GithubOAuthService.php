<?php

namespace App\Services\api\Auth;

use Illuminate\Support\Facades\Http;

class GithubOAuthService
{
    public function resolveIdentityByCode(string $code): array
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
            $email = $this->fetchPrimaryEmail($accessToken);
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
            'oauth_meta' => [
                'access_token' => $accessToken,
                'token_scopes' => $tokenResponse->json()['scope'] ?? null,
                'token_updated_at' => now(),
            ],
        ];
    }

    private function fetchPrimaryEmail(string $accessToken): ?string
    {
        $emailsResponse = Http::timeout(10)
            ->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'User-Agent' => 'portafolio-app',
            ])
            ->get('https://api.github.com/user/emails');

        if (! $emailsResponse->ok()) {
            return null;
        }

        $emails = collect($emailsResponse->json());
        $primary = $emails->firstWhere('primary', true);

        return $primary['email'] ?? ($emails->first()['email'] ?? null);
    }
}
