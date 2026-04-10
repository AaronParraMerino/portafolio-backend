<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenRecuperacion;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class RecuperacionController extends Controller
{
    public function solicitar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'correo' => ['required', 'email', 'exists:usuarios,correo'],
        ], [
            'correo.exists' => 'Datos invalidos', // Mensaje genérico
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $correo = strtolower(trim($validator->validated()['correo']));
        $usuario = Usuario::where('correo', $correo)->first();

        if ($usuario) {
            TokenRecuperacion::where('usuario_id', $usuario->id_usuario)
                ->whereIn('estado', ['inactivo', 'activo'])
                ->update(['estado' => 'expirado']);

            $codigoPlano = strtoupper(Str::random(6));

            TokenRecuperacion::create([
                'usuario_id' => $usuario->id_usuario,
                'token_hash' => Hash::make($codigoPlano),
                'estado' => 'inactivo',
                'fecha_expiracion' => Carbon::now()->addMinutes(2),
                'fecha_creacion' => Carbon::now(),
            ]);

            Mail::raw(
                "Tu codigo de recuperacion es: {$codigoPlano}. Expira en 2 minutos.",
                function ($message) use ($correo) {
                    $message->to($correo)->subject('Codigo de recuperacion');
                }
            );
        }

        return response()->json([
            'message' => 'Si el correo existe, se envio un codigo de recuperacion.',
        ], 200);
    }

    public function activar(Request $request)
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
        if (! $usuario) {
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

        $tokenValido->estado = 'activo';
        $tokenValido->save();

        return response()->json(['message' => 'Codigo validado correctamente.'], 200);
    }

    public function restablecer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'correo' => ['required', 'email'],
            'codigo' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $correo = strtolower(trim($validator->validated()['correo']));
        $codigo = strtoupper(trim($validator->validated()['codigo']));
        $password = $validator->validated()['password'];

        $usuario = Usuario::where('correo', $correo)->first();
        if (! $usuario) {
            return response()->json(['message' => 'No se pudo restablecer.'], 422);
        }

        $tokens = TokenRecuperacion::where('usuario_id', $usuario->id_usuario)
            ->where('estado', 'activo')
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

        $usuario->password = Hash::make($password);
        $usuario->save();

        $tokenValido->estado = 'usado';
        $tokenValido->save();

        
        return response()->json(['message' => 'Contrasena actualizada correctamente.'], 200);
    }
}