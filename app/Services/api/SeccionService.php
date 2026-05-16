<?php

namespace App\Services\api;

use App\Models\SesionBase;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

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

    public function listActiveByUser(int $usuarioId, ?int $currentTokenId = null): Collection
    {
        return SesionBase::query()
            ->where('usuario_id', $usuarioId)
            ->whereNotNull('personal_access_token_id')
            ->orderByDesc('ultima_actividad')
            ->get()
            ->map(function (SesionBase $sesion) use ($currentTokenId) {
                return [
                    'id_rastreo_interno' => $sesion->id_rastreo_interno,
                    'session_token' => $sesion->session_token,
                    'ip_address' => $sesion->ip_address,
                    'pais_codigo' => $sesion->pais_codigo,
                    'navegador_nombre' => $sesion->navegador_nombre,
                    'navegador_version' => $sesion->navegador_version,
                    'sistema_operativo' => $sesion->sistema_operativo,
                    'es_movil' => (bool) $sesion->es_movil,
                    'ultima_actividad' => $sesion->ultima_actividad,
                    'personal_access_token_id' => (int) $sesion->personal_access_token_id,
                    'is_current' => $currentTokenId !== null
                        && (int) $sesion->personal_access_token_id === $currentTokenId,
                ];
            });
    }

    public function closeSessionByIdForUser(int $sesionId, int $usuarioId, ?int $currentTokenId = null): string
    {
        $sesion = SesionBase::query()
            ->where('id_rastreo_interno', $sesionId)
            ->where('usuario_id', $usuarioId)
            ->whereNotNull('personal_access_token_id')
            ->first();

        if (! $sesion) {
            return 'not_found';
        }

        $tokenId = (int) $sesion->personal_access_token_id;

        if ($currentTokenId !== null && $tokenId === $currentTokenId) {
            return 'current_session';
        }

        $this->clearAuthLinksByTokenIds([$tokenId]);
        PersonalAccessToken::query()->where('id', $tokenId)->delete();

        return 'closed';
    }

    public function closeOtherSessionsForUser(int $usuarioId, ?int $currentTokenId = null): int
    {
        $query = SesionBase::query()
            ->where('usuario_id', $usuarioId)
            ->whereNotNull('personal_access_token_id');

        if ($currentTokenId !== null) {
            $query->where('personal_access_token_id', '!=', $currentTokenId);
        }

        $tokenIds = $query
            ->pluck('personal_access_token_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($tokenIds) === 0) {
            return 0;
        }

        $this->clearAuthLinksByTokenIds($tokenIds);

        return PersonalAccessToken::query()
            ->whereIn('id', $tokenIds)
            ->delete();
    }

    public function clearAuthLinksByTokenIds(array $tokenIds): int
    {
        $tokenIds = collect($tokenIds)
            ->filter(fn ($tokenId) => filled($tokenId))
            ->map(fn ($tokenId) => (int) $tokenId)
            ->unique()
            ->values()
            ->all();

        if (count($tokenIds) === 0) {
            return 0;
        }

        return SesionBase::query()
            ->whereIn('personal_access_token_id', $tokenIds)
            ->update([
                'usuario_id' => null,
                'personal_access_token_id' => null,
                'ultima_actividad' => now(),
            ]);
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