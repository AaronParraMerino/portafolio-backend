<?php

namespace App\Services\api;

use App\Models\SesionBase;
use Illuminate\Support\Str;

class SeccionService
{
    public function create(array $data): SesionBase
    {
        $data = $this->normalizeBooleanFields($data);
        $data['session_token'] = $data['session_token'] ?? $this->generateSessionToken();

        return SesionBase::create($data);
    }

    public function findByToken(string $token): ?SesionBase
    {
        return SesionBase::where('session_token', $token)->first();
    }

    public function refresh(SesionBase $sesion, array $data = []): SesionBase
    {
        $data = $this->normalizeBooleanFields($data);

        $sesion->fill($data);
        $sesion->ultima_actividad = now();
        $sesion->save();

        return $sesion;
    }

    public function linkAuthBySessionToken(string $sessionToken, int $usuarioId, int $personalAccessTokenId): ?SesionBase
    {
        $sesion = $this->findByToken($sessionToken);

        if (! $sesion) {
            return null;
        }

        $sesion->usuario_id = $usuarioId;
        $sesion->personal_access_token_id = $personalAccessTokenId;
        $sesion->ultima_actividad = now();
        $sesion->save();

        return $sesion;
    }

    public function linkRecoveryBySessionToken(string $sessionToken, int $tokenRecuperacionId, ?int $usuarioId = null): ?SesionBase
    {
        $sesion = $this->findByToken($sessionToken);

        if (! $sesion) {
            return null;
        }

        if ($usuarioId !== null) {
            $sesion->usuario_id = $usuarioId;
        }

        $sesion->token_recuperacion_id = $tokenRecuperacionId;
        $sesion->ultima_actividad = now();
        $sesion->save();

        return $sesion;
    }

    private function normalizeBooleanFields(array $data): array
    {
        foreach (['es_movil', 'consentimiento_legal'] as $campo) {
            if (array_key_exists($campo, $data) && $data[$campo] !== null) {
                $booleano = filter_var($data[$campo], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($booleano !== null) {
                    $data[$campo] = $booleano ? 'true' : 'false';
                }
            }
        }

        return $data;
    }

    private function generateSessionToken(): string
    {
        do {
            $token = Str::random(64);
        } while (SesionBase::where('session_token', $token)->exists());

        return $token;
    }
}