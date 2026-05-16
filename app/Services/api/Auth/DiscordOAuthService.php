<?php

namespace App\Services\api\Auth;

use Illuminate\Support\Facades\Http;

class DiscordOAuthService
{
    public function resolveIdentityByCode(string $code): array
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
