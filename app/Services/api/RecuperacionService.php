<?php

namespace App\Services\api;

use App\Mail\CodigoRecuperacionMail;
use App\Models\TokenRecuperacion;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RecuperacionService
{
    public function solicitarRecuperacion(string $correo): void
    {
        $correoNormalizado = strtolower(trim($correo));
        $usuario = Usuario::where('correo', $correoNormalizado)->first();

        if (! $usuario) {
            return;
        }

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

        Mail::to($usuario->correo)->send(new CodigoRecuperacionMail(
            codigo: $codigoPlano,
            correo: $usuario->correo,
            minutosExpiracion: 2
        ));
    }

    public function activarToken(string $correo, string $codigo): bool
    {
        $token = $this->buscarTokenPorCorreoYCodigo($correo, $codigo, ['inactivo']);

        if (! $token) {
            return false;
        }

        $token->estado = 'activo';
        $token->save();

        return true;
    }

    public function restablecerPassword(string $correo, string $codigo, string $nuevaPassword): bool
    {
        $token = $this->buscarTokenPorCorreoYCodigo($correo, $codigo, ['activo']);

        if (! $token) {
            return false;
        }

        return DB::transaction(function () use ($token, $nuevaPassword) {
            $usuario = $token->usuario;
            $usuario->password = Hash::make($nuevaPassword);
            $usuario->save();

            $token->estado = 'usado';
            $token->save();

            TokenRecuperacion::where('usuario_id', $usuario->id_usuario)
                ->whereIn('estado', ['inactivo', 'activo'])
                ->where('id_tokenR', '!=', $token->id_tokenR)
                ->update(['estado' => 'expirado']);

            return true;
        });
    }

    private function buscarTokenPorCorreoYCodigo(string $correo, string $codigo, array $estados): ?TokenRecuperacion
    {
        $correoNormalizado = strtolower(trim($correo));
        $codigoNormalizado = strtoupper(trim($codigo));

        $usuario = Usuario::where('correo', $correoNormalizado)->first();
        if (! $usuario) {
            return null;
        }

        $candidatos = TokenRecuperacion::where('usuario_id', $usuario->id_usuario)
            ->whereIn('estado', $estados)
            ->where('fecha_expiracion', '>', Carbon::now())
            ->orderByDesc('id_tokenR')
            ->get();

        foreach ($candidatos as $token) {
            if (Hash::check($codigoNormalizado, $token->token_hash)) {
                return $token;
            }
        }

        return null;
    }
}