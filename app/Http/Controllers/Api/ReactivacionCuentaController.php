<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenRecuperacion;
use App\Models\Usuario;
use App\Services\api\CorreoEnvioService;
use App\Services\api\SeccionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class ReactivacionCuentaController extends Controller
{
    public function __construct(
        private readonly SeccionService $seccionService,
        private readonly CorreoEnvioService $correoEnvioService,
    ) {}

    public function solicitar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'correo' => ['required', 'email', 'exists:usuarios,correo'],
            'session_token' => ['nullable', 'string', 'size:64'],
        ], [
            'correo.exists' => 'Datos invalidos',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $correo = strtolower(trim($validator->validated()['correo']));
        $usuario = Usuario::where('correo', $correo)->first();

        if (! $usuario || $usuario->estado !== 'inactivo') {
            return response()->json([
                'message' => 'No hay una cuenta inactiva asociada a este correo.',
            ], 422);
        }

        TokenRecuperacion::where('usuario_id', $usuario->id_usuario)
            ->whereIn('estado', ['inactivo', 'activo'])
            ->update(['estado' => 'expirado']);

        $codigoPlano = strtoupper(Str::random(6));

        $tokenRecuperacion = TokenRecuperacion::create([
            'usuario_id' => $usuario->id_usuario,
            'token_hash' => Hash::make($codigoPlano),
            'estado' => 'inactivo',
            'fecha_expiracion' => Carbon::now()->addMinutes(6),
            'fecha_creacion' => Carbon::now(),
        ]);

        $sessionToken = $validator->validated()['session_token'] ?? $request->cookie('foliToken');
        if ($sessionToken) {
            $this->seccionService->linkRecoveryBySessionToken(
                $sessionToken,
                $tokenRecuperacion->id_tokenR,
                $usuario->id_usuario
            );
        }

        try {
            $this->correoEnvioService->enviarVista(
                $correo,
                'Codigo de reactivacion de cuenta',
                'emails.codigo_reactivacion',
                [
                    'codigo' => $codigoPlano,
                    'correo' => $correo,
                    'minutosExpiracion' => 6,
                ]
            );
        } catch (\Throwable $exception) {
            Log::error('No se pudo enviar el correo de reactivacion.', [
                'correo' => $correo,
                'usuario_id' => $usuario->id_usuario,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo enviar el codigo de reactivacion. Intenta nuevamente.',
            ], 503);
        }

        return response()->json([
            'message' => 'Enviamos un codigo para restablecer tu cuenta.',
        ], 200);
    }

    public function confirmar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'correo' => ['required', 'email'],
            'codigo' => ['required', 'string', 'size:6'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $correo = strtolower(trim($validator->validated()['correo']));
        $codigo = strtoupper(trim($validator->validated()['codigo']));

        $usuario = Usuario::where('correo', $correo)->first();
        if (! $usuario || $usuario->estado !== 'inactivo') {
            return response()->json(['message' => 'Codigo invalido o expirado.'], 422);
        }

        $tokens = TokenRecuperacion::where('usuario_id', $usuario->id_usuario)
            ->where('estado', 'inactivo')
            ->where('fecha_expiracion', '>', Carbon::now())
            ->orderByDesc('id_tokenR')
            ->get();

        $tokenValido = null;
        foreach ($tokens as $token) {
            if (Hash::check($codigo, $token->token_hash)) {
                $tokenValido = $token;
                break;
            }
        }

        if (! $tokenValido) {
            return response()->json(['message' => 'Codigo invalido o expirado.'], 422);
        }

        $usuario->estado = 'activo';
        $usuario->save();

        $tokenValido->estado = 'usado';
        $tokenValido->save();

        return response()->json([
            'message' => 'Cuenta restablecida correctamente. Ya puedes iniciar sesion.',
        ], 200);
    }

}
