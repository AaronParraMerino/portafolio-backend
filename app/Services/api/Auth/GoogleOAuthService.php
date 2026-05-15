<?php

namespace App\Services\api\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleOAuthService
{
    public function resolveIdentityByIdToken(string $idToken): array
    {
        $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if (! $response->ok()) {
            return ['status' => 'invalid'];
        }

        return $this->identityFromTokenPayload($response->json());
    }

    public function resolveIdentityByCode(string $code): array
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
        $userInfo = Http::timeout(10)
            ->withToken($accessToken)
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        if ($userInfo->ok()) {
            $payload['name'] = $userInfo->json()['name'] ?? ($payload['name'] ?? null);
        }

        return $this->identityFromTokenPayload($payload);
    }

    private function identityFromTokenPayload(array $payload): array
    {
        $clientId = config('services.google.client_id');
        $providerId = (string) ($payload['sub'] ?? '');
        $email = strtolower(trim($payload['email'] ?? ''));
        $verified = in_array($payload['email_verified'] ?? false, [true, 'true'], true);

        if (! $clientId || ($payload['aud'] ?? null) !== $clientId || ! $providerId || ! $email || ! $verified) {
            return ['status' => 'invalid'];
        }

        return [
            'status' => 'success',
            'provider_user_id' => $providerId,
            'email' => $email,
            'nombre' => $payload['name'] ?? null,
            'foto_url' => $this->uploadImageToSupabase($payload['picture'] ?? null),
        ];
    }

    private function uploadImageToSupabase(?string $imageUrl): ?string
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

            $filePath = 'profile/' . Str::uuid() . '.' . $extension;
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $urlBase . '/storage/v1/object/' . $bucket . '/' . $filePath,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $imageResponse->body(),
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

            return $urlBase . '/storage/v1/object/public/' . $bucket . '/' . $filePath;
        } catch (\Throwable) {
            return $imageUrl;
        }
    }
}
