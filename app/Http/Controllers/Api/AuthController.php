<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\AuthService;
use App\Services\api\GithubRepositorySyncService;
use App\Services\api\SeccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly SeccionService $seccionService,
        private readonly GithubRepositorySyncService $githubRepositorySyncService,
    )
    {
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'correo' => ['required', 'email', 'max:255', 'unique:usuarios,correo'],
            'password' => ['required', 'string', 'min:8'],
            'telefono' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $result = $this->authService->register($data);

        return response()->json([
            'message' => 'Usuario registrado correctamente',
            'token' => $result['token'],
            'data' => $result['usuario'],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'correo' => ['required', 'email'],
            'password' => ['required', 'string'],
            'session_token' => ['nullable', 'string', 'size:64'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $result = $this->authService->attemptLogin($data);

        if ($result['status'] === 'invalid') {
            return response()->json([
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        if ($result['status'] === 'blocked') {
            return response()->json([
                'message' => 'Usuario bloqueado',
            ], 403);
        }
        
        $sessionToken = $data['session_token'] ?? $request->cookie('foliToken');

        if ($sessionToken && isset($result['personal_access_token_id'])) {
            $this->seccionService->linkAuthBySessionToken(
                $sessionToken,
                $result['usuario']->id_usuario,
                (int) $result['personal_access_token_id']
            );
        }

        return response()->json([
            'message' => 'Inicio de sesión correcto',
            'token' => $result['token'],
            'data' => $result['usuario'],
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->currentAccessToken()) {
            return response()->json([
                'message' => 'No hay sesión activa',
            ], 401);
        }

        $this->authService->logout($user);

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ], 200);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user(),
        ], 200);
    }

    public function googleAuth(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'id_token' => ['required', 'string'],
        'session_token' => ['nullable', 'string', 'size:64'],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Datos inválidos',
            'errors'  => $validator->errors(),
        ], 422);
    }

    $data = $validator->validated();
    $result = $this->authService->loginWithGoogle($data['id_token']);

    if ($result['status'] === 'invalid') {
        return response()->json(['message' => 'No se pudo validar la cuenta de Google'], 401);
    }

    if ($result['status'] === 'blocked') {
        return response()->json(['message' => 'Usuario bloqueado'], 403);
    }

    if ($result['status'] === 'provider_conflict') {
        return response()->json([
            'message' => 'Ya existe una cuenta de Google distinta vinculada a este correo',
        ], 409);
    }

    if ($result['status'] === 'already_linked') {
        return response()->json([
            'message' => 'Esta cuenta OAuth ya está vinculada a otra cuenta',
        ], 409);
    }

    if ($result['status'] === 'needs_link_confirmation') {
        return response()->json([
            'status'              => 'needs_link_confirmation',
            'link_token'          => $result['link_token'],
            'correo'              => $result['correo'],
            'provider'            => $result['provider'],
            'verification_method' => $result['verification_method'],
            'message'             => 'Ya existe una cuenta con este correo.',
        ], 200);
    }

    $sessionToken = $data['session_token'] ?? $request->cookie('foliToken');

    if ($sessionToken && isset($result['personal_access_token_id'])) {
        $this->seccionService->linkAuthBySessionToken(
            $sessionToken,
            $result['usuario']->id_usuario,
            (int) $result['personal_access_token_id']
        );
    }

    return response()->json([
        'message'  => 'Autenticación con Google correcta',
        'token'    => $result['token'],
        'data'     => $result['usuario'],
        'foto_url' => $result['foto_url'],
    ], 200);
}

// ─────────────────────────────────────────────────────────────
//  OAuth redirect / callback — GitHub, GitLab, Discord
// ─────────────────────────────────────────────────────────────

private const OAUTH_PROVIDERS = [
    'google' => [
        'url'    => 'https://accounts.google.com/o/oauth2/v2/auth',
        'params' => [
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'offline',
            'prompt' => 'select_account',
        ],
    ],
    'github' => [
        'url'    => 'https://github.com/login/oauth/authorize',
        'params' => ['scope' => 'read:user user:email repo'],
    ],
    'gitlab' => [
        'url'    => 'https://gitlab.com/oauth/authorize',
        'params' => ['response_type' => 'code', 'scope' => 'read_user'],
    ],
    'discord' => [
        'url'    => 'https://discord.com/api/oauth2/authorize',
        'params' => ['response_type' => 'code', 'scope' => 'identify email'],
    ],
];

public function oauthRedirect(string $provider): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
{
    if (! array_key_exists($provider, self::OAUTH_PROVIDERS)) {
        return response()->json(['message' => 'Proveedor no soportado'], 400);
    }

    $config     = self::OAUTH_PROVIDERS[$provider];
    $clientId   = config("services.{$provider}.client_id");
    $redirectUri = config("services.{$provider}.redirect");

    $params = array_merge($config['params'], [
        'client_id'    => $clientId,
        'redirect_uri' => $redirectUri,
    ]);

    return redirect($config['url'] . '?' . http_build_query($params));
}

public function linkedProviders(Request $request): JsonResponse
{
    $user = $request->user();

    if (! $user) {
        return response()->json(['message' => 'No hay sesión activa'], 401);
    }

    return response()->json([
        'data' => $this->authService->getLinkedProviders($user->id_usuario),
    ]);
}

public function oauthConnectUrl(string $provider, Request $request): JsonResponse
{
    if (! array_key_exists($provider, self::OAUTH_PROVIDERS)) {
        return response()->json(['message' => 'Proveedor no soportado'], 400);
    }

    $user = $request->user();

    if (! $user) {
        return response()->json(['message' => 'No hay sesión activa'], 401);
    }

    $state = (string) Str::uuid();

    Cache::put('oauth_connect:' . $state, [
        'usuario_id' => $user->id_usuario,
        'provider' => $provider,
    ], now()->addMinutes(10));

    $config = self::OAUTH_PROVIDERS[$provider];
    $clientId = config("services.{$provider}.client_id");
    $redirectUri = config("services.{$provider}.redirect");

    $params = array_merge($config['params'], [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'state' => $state,
    ]);

    return response()->json([
        'url' => $config['url'] . '?' . http_build_query($params),
    ]);
}

public function oauthUnlink(string $provider, Request $request): JsonResponse
{
    if (! array_key_exists($provider, self::OAUTH_PROVIDERS)) {
        return response()->json(['message' => 'Proveedor no soportado'], 400);
    }

    $user = $request->user();

    if (! $user) {
        return response()->json(['message' => 'No hay sesión activa'], 401);
    }

    $result = $this->authService->unlinkOAuthAccountFromUser((int) $user->id_usuario, $provider);

    if ($result['status'] === 'not_found') {
        return response()->json(['message' => 'No existe una cuenta vinculada para ese proveedor.'], 404);
    }

    if ($result['status'] === 'last_provider') {
        return response()->json(['message' => 'No puedes desvincular tu única cuenta vinculada. Establece primero una contraseña en Configuración → Cambiar contraseña.'], 422);
    }

    return response()->json([
        'message' => 'Cuenta desvinculada correctamente.',
    ]);
}

public function syncGithubRepos(Request $request): JsonResponse
{
    $user = $request->user();

    if (! $user) {
        return response()->json(['message' => 'No hay sesión activa'], 401);
    }

    $result = $this->githubRepositorySyncService->syncForUsuario((int) $user->id_usuario);

    return match ($result['status'] ?? 'error') {
        'success' => response()->json($result, 200),
        'not_linked' => response()->json($result, 404),
        'missing_token' => response()->json($result, 422),
        'invalid_token' => response()->json($result, 401),
        default => response()->json($result, 502),
    };
}

public function githubDetectedRepos(Request $request): JsonResponse
{
    $user = $request->user();

    if (! $user) {
        return response()->json(['message' => 'No hay sesión activa'], 401);
    }

    $refresh = filter_var($request->query('refresh', false), FILTER_VALIDATE_BOOLEAN);

    if ($refresh) {
        $sync = $this->githubRepositorySyncService->syncForUsuario((int) $user->id_usuario);

        if (($sync['status'] ?? 'error') !== 'success') {
            return match ($sync['status'] ?? 'error') {
                'not_linked' => response()->json($sync, 404),
                'missing_token' => response()->json($sync, 422),
                'invalid_token' => response()->json($sync, 401),
                default => response()->json($sync, 502),
            };
        }
    }

    $repos = \App\Models\ProyectoRepositorio::query()
        ->with('github')
        ->where('proveedor', 'github')
        ->whereHas('github')
        ->orderByDesc('updated_at')
        ->get()
        ->map(function ($repo) use ($user) {
            $github = $repo->github;

            $validacion = \App\Models\UsuarioRepositorioValidacion::query()
                ->where('id_usuario', $user->id_usuario)
                ->where('id_repositorio_github', $github->id_repositorio_github)
                ->first();

            $participacion = null;
            $proyecto = null;

            if ($repo->id_proyecto) {
                $participacion = DB::table('participaciones')
                    ->where('id_usuario', $user->id_usuario)
                    ->where('id_proyecto', $repo->id_proyecto)
                    ->whereNull('deleted_at')
                    ->first();

                $proyecto = DB::table('proyectos')
                    ->where('id_proyecto', $repo->id_proyecto)
                    ->whereNull('deleted_at')
                    ->select('id_proyecto', 'titulo', 'descripcion', 'estado_publicacion', 'estado_desarrollo')
                    ->first();
            }

            $repoEnUso = ! is_null($repo->id_proyecto);
            $validado = (bool) ($validacion->validado ?? false);

            if ($repoEnUso && ($participacion || ! $validado || ! $proyecto)) {
                return null;
            }

            return [
                'id_proyecto_repositorio' => $repo->id_proyecto_repositorio,
                'id_proyecto' => $repo->id_proyecto,
                'nombre' => $repo->nombre,
                'url_repositorio' => $repo->url_repositorio,
                'descripcion' => $repo->descripcion,
                'tipo' => $repo->tipo,
                'estado_vinculacion' => $repoEnUso ? 'en_uso' : 'libre',
                'puede_unirse' => $repoEnUso && $validado && ! $participacion,
                'proyecto' => $proyecto ? [
                    'id_proyecto' => $proyecto->id_proyecto,
                    'titulo' => $proyecto->titulo,
                    'descripcion' => $proyecto->descripcion,
                    'estado_publicacion' => $proyecto->estado_publicacion,
                    'estado_desarrollo' => $proyecto->estado_desarrollo,
                ] : null,
                'repo_github' => [
                    'id_repositorio_github' => $github->id_repositorio_github,
                    'github_repo_id' => $github->github_repo_id,
                    'owner' => $github->github_owner,
                    'repo_name' => $github->github_repo_name,
                    'is_private' => $github->is_private,
                    'is_archived' => $github->is_archived,
                    'last_push_at' => $github->last_push_at,
                    'stars_count' => $github->stars_count,
                ],
                'validacion' => [
                    'validado' => (bool) ($validacion->validado ?? false),
                    'relacion_github' => $validacion->relacion_github ?? 'unknown',
                    'es_propietario' => (bool) ($validacion->es_propietario ?? false),
                    'ultima_verificacion_at' => $validacion->ultima_verificacion_at ?? null,
                ],
            ];
        })
        ->filter()
        ->values();

    return response()->json([
        'status' => 'success',
        'data' => $repos,
    ]);
}

public function githubRepoLanguages(Request $request): JsonResponse
{
    $user = $request->user();

    if (! $user) {
        return response()->json(['message' => 'No hay sesión activa'], 401);
    }

    $validator = Validator::make($request->all(), [
        'repo_url' => ['required', 'string', 'max:500'],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Datos inválidos',
            'errors' => $validator->errors(),
        ], 422);
    }

    $result = $this->githubRepositorySyncService->fetchRepoLanguagesForUsuario(
        (int) $user->id_usuario,
        (string) $validator->validated()['repo_url']
    );

    return match ($result['status'] ?? 'error') {
        'success' => response()->json($result, 200),
        'invalid_payload' => response()->json($result, 422),
        'not_linked' => response()->json($result, 404),
        'missing_token' => response()->json($result, 422),
        'invalid_token' => response()->json($result, 401),
        'repo_not_found_or_no_access' => response()->json($result, 404),
        'private_or_no_access' => response()->json([
            ...$result,
            'message' => 'Necesita vincular cuenta, actualmente no cuenta con los permisos.',
        ], 403),
        default => response()->json($result, 502),
    };
}

public function attachDetectedReposToProject(Request $request): JsonResponse
{
    $user = $request->user();

    if (! $user) {
        return response()->json(['message' => 'No hay sesión activa'], 401);
    }

    $validator = Validator::make($request->all(), [
        'id_proyecto'                          => ['required', 'integer', 'min:1'],
        'repositorios_ids'                     => ['required', 'array', 'min:1'],
        'repositorios_ids.*'                   => ['integer', 'min:1'],
        'participacion_data'                   => ['nullable', 'array'],
        'participacion_data.rol'               => ['nullable', 'string', 'max:100'],
        'participacion_data.descripcion_aporte'=> ['nullable', 'string', 'max:600'],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Datos inválidos',
            'errors' => $validator->errors(),
        ], 422);
    }

    $data = $validator->validated();

    $result = $this->githubRepositorySyncService->attachDetectedReposToProject(
        (int) $user->id_usuario,
        (int) $data['id_proyecto'],
        $data['repositorios_ids'],
        $data['participacion_data'] ?? [],
    );

    return match ($result['status'] ?? 'error') {
        'success' => response()->json($result, 200),
        'linked_existing_project' => response()->json($result, 200),
        'invalid_payload' => response()->json($result, 422),
        'participacion_not_found' => response()->json($result, 404),
        'repos_not_found' => response()->json($result, 404),
        'already_assigned' => response()->json($result, 409),
        default => response()->json($result, 500),
    };
}

public function oauthCallback(string $provider, Request $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
{
    $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

    if (! array_key_exists($provider, self::OAUTH_PROVIDERS)) {
        return redirect($frontendUrl . '/auth/login?oauth_error=unsupported');
    }

    $code = $request->query('code');
    if (! $code) {
        return redirect($frontendUrl . '/auth/login?oauth_error=cancelled');
    }

    $state = (string) $request->query('state', '');
    if ($state !== '') {
        $connectData = Cache::pull('oauth_connect:' . $state);

        if (is_array($connectData) && ($connectData['provider'] ?? null) === $provider) {
            $result = $this->authService->linkOAuthAccountToUser(
                (int) $connectData['usuario_id'],
                $provider,
                (string) $code,
            );

            if ($result['status'] === 'success' || $result['status'] === 'already_connected') {
                $params = http_build_query([
                    'oauth_connect' => 'success',
                    'provider' => $provider,
                ]);
                return redirect($frontendUrl . '/dashboard/settings/vincular-cuenta?' . $params);
            }

            $error = $result['status'] ?? 'invalid';
            $params = http_build_query([
                'oauth_connect' => 'error',
                'provider' => $provider,
                'oauth_connect_error' => $error,
            ]);
            return redirect($frontendUrl . '/dashboard/settings/vincular-cuenta?' . $params);
        }
    }

    if ($provider === 'google') {
        return redirect($frontendUrl . '/auth/login?oauth_error=unsupported');
    }

    $method = 'loginWith' . ucfirst($provider);
    $result = $this->authService->$method($code);

    if ($result['status'] === 'invalid') {
        return redirect($frontendUrl . '/auth/login?oauth_error=invalid');
    }

    if ($result['status'] === 'blocked') {
        return redirect($frontendUrl . '/auth/login?oauth_error=blocked');
    }

    if ($result['status'] === 'provider_conflict') {
        return redirect($frontendUrl . '/auth/login?oauth_error=provider_conflict');
    }

    if ($result['status'] === 'already_linked') {
        return redirect($frontendUrl . '/auth/login?oauth_error=already_linked');
    }

    if ($result['status'] === 'needs_link_confirmation') {
        $params = http_build_query([
            'oauth_action'        => 'link_confirm',
            'link_token'          => $result['link_token'],
            'correo'              => $result['correo'],
            'provider'            => $result['provider'],
            'verification_method' => $result['verification_method'],
        ]);
        return redirect($frontendUrl . '/auth/callback?' . $params);
    }

    $sessionToken = $request->cookie('foliToken');
    if ($sessionToken && isset($result['personal_access_token_id'])) {
        $this->seccionService->linkAuthBySessionToken(
            $sessionToken,
            $result['usuario']->id_usuario,
            (int) $result['personal_access_token_id']
        );
    }

    $token   = $result['token'];
    $usuario = urlencode(json_encode($result['usuario']));

    return redirect($frontendUrl . '/auth/callback?token=' . $token . '&usuario=' . $usuario);
}

public function confirmOAuthLink(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'link_token'    => ['required', 'string'],
        'password'      => ['nullable', 'string'],
        'code'          => ['nullable', 'string', 'size:6'],
        'session_token' => ['nullable', 'string', 'size:64'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
    }

    $data   = $validator->validated();
    $result = $this->authService->confirmOAuthLink(
        $data['link_token'],
        $data['password'] ?? null,
        $data['code'] ?? null,
    );

    if ($result['status'] === 'invalid') {
        return response()->json(['message' => 'El enlace de confirmación expiró o no es válido'], 401);
    }

    if ($result['status'] === 'blocked') {
        return response()->json(['message' => 'Usuario bloqueado'], 403);
    }

    if ($result['status'] === 'wrong_credentials') {
        return response()->json(['status' => 'wrong_credentials', 'message' => 'Credenciales incorrectas'], 401);
    }

    $sessionToken = $data['session_token'] ?? $request->cookie('foliToken');

    if ($sessionToken && isset($result['personal_access_token_id'])) {
        $this->seccionService->linkAuthBySessionToken(
            $sessionToken,
            $result['usuario']->id_usuario,
            (int) $result['personal_access_token_id']
        );
    }

    return response()->json([
        'message' => 'Cuenta vinculada e inicio de sesión correcto',
        'token'   => $result['token'],
        'data'    => $result['usuario'],
    ], 200);
}
}
