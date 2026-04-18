<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SesionHardware;
use App\Services\api\SeccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;


class SeccionController extends Controller
{
    public function __construct(private readonly SeccionService $seccionService)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ip_address'               => ['nullable', 'string', 'max:45'],
            'isp_proveedor'            => ['nullable', 'string', 'max:100'],
            'pais_codigo'              => ['nullable', 'string', 'size:2'],
            'navegador_nombre'         => ['nullable', 'string', 'max:50'],
            'navegador_version'        => ['nullable', 'string', 'max:20'],
            'sistema_operativo'        => ['nullable', 'string', 'max:50'],
            'es_movil'                 => ['nullable', 'boolean'],
            'resolucion_pantalla'      => ['nullable', 'string', 'max:15'],
            'idioma_preferido'         => ['nullable', 'string', 'max:10'],
            'zona_horaria'             => ['nullable', 'string', 'max:50'],
            'fuente_url'               => ['nullable', 'string'],
            'pagina_entrada'           => ['nullable', 'string'],
            'consentimiento_legal'     => ['nullable', 'boolean'],
            'usuario_id'               => ['nullable', 'integer', 'exists:usuarios,id_usuario'],
            'personal_access_token_id' => ['nullable', 'integer', 'exists:personal_access_tokens,id'],
            'token_recuperacion_id'    => ['nullable', 'integer', 'exists:token_recuperaciones,id_tokenR'],
            'bitacora_id'              => ['nullable', 'integer', 'exists:bitacoras,id_bitacora'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

        // ── Backend llena lo que el browser no puede saber ──────────────────
        $ip = $request->ip();
        $payload['ip_address']   = $payload['ip_address']   ?? $ip;
        $payload['fecha_ingreso']    = now();
        $payload['ultima_actividad'] = now();

        // Geo lookup solo si la IP es pública (no local)
        $esIpLocal = in_array($ip, ['127.0.0.1', '::1'], true)
                     || str_starts_with($ip, '192.168.')
                     || str_starts_with($ip, '10.');

        if (!$esIpLocal && empty($payload['pais_codigo']) && empty($payload['isp_proveedor'])) {
            try {
                $geo = Http::timeout(3)
                    ->get("http://ip-api.com/json/{$ip}?fields=status,countryCode,isp")
                    ->json();

                if (($geo['status'] ?? null) === 'success') {
                    $payload['pais_codigo']   = $geo['countryCode'] ?? null;
                    $payload['isp_proveedor'] = isset($geo['isp'])
                        ? mb_substr($geo['isp'], 0, 100)
                        : null;
                }
            } catch (\Throwable) {
                // Si falla la geo, no es crítico, continuamos sin esos datos
            }
        }
        // ────────────────────────────────────────────────────────────────────

        $cookieToken = $request->cookie('foliToken');

        if ($cookieToken) {
            $existente = $this->seccionService->findByToken($cookieToken);

            if ($existente) {
                $sesion = $this->seccionService->refresh($existente, $payload);

                return response()->json([
                    'message'       => 'Sesion existente actualizada',
                    'session_token' => $sesion->session_token,
                    'data'          => $sesion,
                    'origen'        => 'cookie_existente',
                ], 200);
            }
        }

        $sesion = $this->seccionService->create($payload);

        $cookie = cookie(
            'foliToken',
            $sesion->session_token,
            60 * 24 * 30,
            config('session.path', '/'),
            config('session.domain'),
            (bool) config('session.secure', false),
            true,
            false,
            config('session.same_site', 'lax')
        );

        return response()->json([
            'message'       => 'Sesion base registrada correctamente',
            'session_token' => $sesion->session_token,
            'data'          => $sesion,
            'origen'        => 'nueva_cookie',
        ], 201)->cookie($cookie);
    }
    public function hardware(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
        'aceptado' => ['required', 'boolean'],
        'version_politica' => ['required', 'string', 'max:20'],

        'gpu_renderer' => ['nullable', 'string', 'max:255'],
        'cpu_nucleos' => ['nullable', 'integer', 'min:1', 'max:128'],
        'ram_estimada' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
        'hdr_soporte' => ['nullable', 'boolean'],
        'bateria_nivel' => ['nullable', 'string', 'max:20'],
        'uuid_persistente' => ['nullable', 'uuid'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

        // Si no acepta, no creamos sesion_hardware
        if (!filter_var($payload['aceptado'], FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'message' => 'Consentimiento rechazado: no se almacena huella hardware',
            ], 200);
        }

        $cookieToken = $request->cookie('foliToken');
        if (!$cookieToken) {
            return response()->json([
                'message' => 'Cookie foliToken no encontrada',
            ], 404);
        }

        $sesionBase = $this->seccionService->findByToken($cookieToken);
        if (!$sesionBase) {
            return response()->json([
                'message' => 'Sesion base no encontrada para el token',
            ], 404);
        }

        // Si acepta, marcamos consentimiento en sesion_base.
        $sesionBase->consentimiento_legal = 'true';
        $sesionBase->ultima_actividad = now();
        $sesionBase->save();

        $fecha = now();
        $uuid = $payload['uuid_persistente'] ?? (string) Str::uuid();

        // Firma digital de consentimiento (auditable)
        $firma = hash_hmac(
            'sha256',
            $sesionBase->id_rastreo_interno . '|' .
            $sesionBase->session_token . '|' .
            $uuid . '|' .
            $payload['version_politica'] . '|' .
            $fecha->toIso8601String(),
            (string) config('app.key')
        );

        $dataHardware = [
            'id_rastreo_base' => $sesionBase->id_rastreo_interno,
            'gpu_renderer' => $payload['gpu_renderer'] ?? null,
            'cpu_nucleos' => $payload['cpu_nucleos'] ?? null,
            'ram_estimada' => $payload['ram_estimada'] ?? null,
            'hdr_soporte' => isset($payload['hdr_soporte'])
                ? filter_var($payload['hdr_soporte'], FILTER_VALIDATE_BOOLEAN)
                : null,
            'bateria_nivel' => $payload['bateria_nivel'] ?? null,
            'uuid_persistente' => $uuid,
            'consentimiento_fecha' => $fecha,
            'consentimiento_version' => $payload['version_politica'],
            'consentimiento_ip' => $request->ip(),
            'consentimiento_user_agent' => $request->userAgent(),
            'consentimiento_firma' => $firma,
        ];

        $hardware = SesionHardware::updateOrCreate(
            ['id_rastreo_base' => $sesionBase->id_rastreo_interno],
            $dataHardware
        );

        return response()->json([
            'message' => 'Consentimiento y hardware guardados correctamente',
            'data' => $hardware,
        ], 200);
    }
    
}