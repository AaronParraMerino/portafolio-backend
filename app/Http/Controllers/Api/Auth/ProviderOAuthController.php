<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\api\Auth\OAuthProviderAuthorizationService;
use App\Services\api\AuthService;
use App\Services\api\SeccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

abstract class ProviderOAuthController extends Controller
{
    public function __construct(
        protected readonly AuthService $authService,
        protected readonly OAuthProviderAuthorizationService $authorizationService,
        protected readonly SeccionService $seccionService,
    ) {
    }

    abstract protected function provider(): string;

    public function redirect(): RedirectResponse|JsonResponse
    {
        $url = $this->authorizationService->buildAuthorizationUrl($this->provider());

        if (! $url) {
            return response()->json(['message' => 'Proveedor no soportado'], 400);
        }

        return redirect($url);
    }

    public function connectUrl(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No hay sesion activa'], 401);
        }

        $state = (string) Str::uuid();
        Cache::put('oauth_connect:' . $state, [
            'usuario_id' => $user->id_usuario,
            'provider' => $this->provider(),
        ], now()->addMinutes(10));

        $url = $this->authorizationService->buildAuthorizationUrl($this->provider(), $state);

        if (! $url) {
            return response()->json(['message' => 'Proveedor no soportado'], 400);
        }

        return response()->json(['url' => $url]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No hay sesion activa'], 401);
        }

        $result = $this->authService->unlinkOAuthAccountFromUser((int) $user->id_usuario, $this->provider());

        if ($result['status'] === 'not_found') {
            return response()->json(['message' => 'No existe una cuenta vinculada para ese proveedor.'], 404);
        }

        if ($result['status'] === 'last_provider') {
            return response()->json(['message' => 'No puedes desvincular tu unica cuenta vinculada. Establece primero una contrasena.'], 422);
        }

        return response()->json(['message' => 'Cuenta desvinculada correctamente.']);
    }

    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $code = $request->query('code');

        if (! $code) {
            return redirect($frontendUrl . '/auth/login?oauth_error=cancelled');
        }

        $provider = $this->provider();
        $state = (string) $request->query('state', '');

        if ($state !== '') {
            $connectData = Cache::pull('oauth_connect:' . $state);

            if (is_array($connectData) && ($connectData['provider'] ?? null) === $provider) {
                return $this->handleConnectCallback($frontendUrl, (int) $connectData['usuario_id'], $provider, (string) $code);
            }
        }

        if ($provider === 'google') {
            return redirect($frontendUrl . '/auth/login?oauth_error=unsupported');
        }

        return $this->handleLoginCallback($request, $frontendUrl, $provider, (string) $code);
    }

    protected function linkSessionToken(Request $request, ?string $sessionToken, array $authResult): void
    {
        $sessionToken ??= $request->cookie('foliToken');

        if (! $sessionToken || ! isset($authResult['personal_access_token_id'])) {
            return;
        }

        $this->seccionService->linkAuthBySessionToken(
            $sessionToken,
            $authResult['usuario']->id_usuario,
            (int) $authResult['personal_access_token_id'],
        );
    }

    private function handleConnectCallback(string $frontendUrl, int $usuarioId, string $provider, string $code): RedirectResponse
    {
        $result = $this->authService->linkOAuthAccountToUser($usuarioId, $provider, $code);

        if ($result['status'] === 'success' || $result['status'] === 'already_connected') {
            return redirect($frontendUrl . '/dashboard/settings/vincular-cuenta?' . http_build_query([
                'oauth_connect' => 'success',
                'provider' => $provider,
            ]));
        }

        return redirect($frontendUrl . '/dashboard/settings/vincular-cuenta?' . http_build_query([
            'oauth_connect' => 'error',
            'provider' => $provider,
            'oauth_connect_error' => $result['status'] ?? 'invalid',
        ]));
    }

    private function handleLoginCallback(Request $request, string $frontendUrl, string $provider, string $code): RedirectResponse
    {
        $method = 'loginWith' . ucfirst($provider);
        $result = $this->authService->$method($code);

        if ($result['status'] === 'invalid') {
            return redirect($frontendUrl . '/auth/login?oauth_error=invalid');
        }

        if ($result['status'] === 'blocked') {
            return redirect($frontendUrl . '/auth/login?' . http_build_query([
                'oauth_error' => 'blocked',
                'razon' => $result['razon'] ?? 'Tu cuenta fue bloqueada por administracion.',
            ]));
        }

        if ($result['status'] === 'inactive') {
            return redirect($frontendUrl . '/auth/login?' . http_build_query([
                'oauth_error' => 'inactive',
                'correo' => $result['correo'] ?? '',
            ]));
        }

        if ($result['status'] === 'paused') {
            return redirect($frontendUrl . '/auth/login?oauth_error=paused');
        }

        if ($result['status'] === 'provider_conflict') {
            return redirect($frontendUrl . '/auth/login?oauth_error=provider_conflict');
        }

        if ($result['status'] === 'already_linked') {
            return redirect($frontendUrl . '/auth/login?oauth_error=already_linked');
        }

        if ($result['status'] === 'needs_link_confirmation') {
            return redirect($frontendUrl . '/auth/callback?' . http_build_query([
                'oauth_action' => 'link_confirm',
                'link_token' => $result['link_token'],
                'correo' => $result['correo'],
                'provider' => $result['provider'],
                'verification_method' => $result['verification_method'],
            ]));
        }

        $this->linkSessionToken($request, null, $result);

        return redirect($frontendUrl . '/auth/callback?' . http_build_query([
            'token' => $result['token'],
            'usuario' => json_encode($result['usuario']),
        ]));
    }
}
