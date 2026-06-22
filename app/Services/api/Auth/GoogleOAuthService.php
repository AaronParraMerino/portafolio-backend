<?php

namespace App\Services\api\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $urlBase = rtrim((string) env('SUPABASE_URL'), '/');
        $key = env('SUPABASE_KEY');

        if (! $bucket || ! $urlBase || ! $key) {
            return $imageUrl;
        }

        try {
            $imageResponse = Http::timeout(10)->get($imageUrl);
            if (! $imageResponse->ok()) {
                Log::warning('No se pudo descargar la foto de Google para guardarla en Supabase Storage.', [
                    'status' => $imageResponse->status(),
                    'source_url' => $imageUrl,
                ]);

                return $imageUrl;
            }

            $contentType = $imageResponse->header('Content-Type') ?: 'image/jpeg';
            $mimeType = strtolower(explode(';', $contentType)[0]);

            $extension = match ($mimeType) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'image/svg+xml' => 'svg',
                default => 'jpg',
            };

            $filePath = 'profile/' . Str::uuid() . '.' . $extension;

            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $key,
                    'apikey' => $key,
                    'Content-Type' => $contentType,
                ])
                ->withBody($imageResponse->body(), $contentType)
                ->post($urlBase . '/storage/v1/object/' . $bucket . '/' . $filePath);

            if ($response->failed()) {
                Log::warning('No se pudo guardar la foto de Google en Supabase Storage.', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'path' => $filePath,
                ]);

                return $imageUrl;
            }

            return $urlBase . '/storage/v1/object/public/' . $bucket . '/' . $filePath;
        } catch (\Throwable $exception) {
            Log::warning('No se pudo guardar la foto de Google en Supabase Storage.', [
                'error' => $exception->getMessage(),
            ]);

            return $imageUrl;
        }
    }
}
