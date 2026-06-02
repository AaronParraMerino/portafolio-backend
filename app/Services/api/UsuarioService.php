<?php

namespace App\Services\api;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsuarioService
{
    public function getAll()
    {
        return Usuario::all();
    }

    public function findById(int $id): ?Usuario
    {
        return Usuario::find($id);
    }

    public function create(array $data): Usuario
    {
        $data['password'] = Hash::make($data['password']);
        $data['rol'] = 'usuario';
        $data['estado'] = 'activo';
        $data['intentos_fallidos'] = 0;

        return Usuario::create($data);
    }

    public function update(Usuario $usuario, array $data): Usuario
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $usuario->update($data);

        return $usuario->fresh();
    }

    public function activate(Usuario $usuario): void
    {
        $usuario->update([
            'estado' => 'activo',
            'intentos_fallidos' => 0,
            'fecha_bloqueo' => null,
        ]);
    }

    public function delete(Usuario $usuario): void
    {
        $this->restrictAccount($usuario, 'inactivo');
    }

    public function block(Usuario $usuario): void
    {
        $this->restrictAccount($usuario, 'bloqueado');
    }

    public function pause(Usuario $usuario): void
    {
        $usuario->update([
            'estado' => 'pausado',
            'intentos_fallidos' => 0,
            'fecha_bloqueo' => null,
        ]);
    }

    public function updateRole(Usuario $usuario, string $rol): Usuario
    {
        $usuario->update([
            'rol' => $rol,
        ]);

        return $usuario->fresh();
    }

    private function restrictAccount(Usuario $usuario, string $estado): void
    {
        DB::transaction(function () use ($usuario, $estado) {
            $usuario->update([
                'estado' => $estado,
                'intentos_fallidos' => 0,
                'fecha_bloqueo' => null,
            ]);

            DB::table('visibilidad_campos')
                ->where('usuario_id', $usuario->id_usuario)
                ->update(['visible' => DB::raw('FALSE')]);

            DB::table('enlaces')
                ->where('id_usuario', $usuario->id_usuario)
                ->update(['es_visible' => DB::raw('FALSE')]);

            DB::table('habilidades_usuario')
                ->where('usuario_id', $usuario->id_usuario)
                ->update(['es_visible' => DB::raw('FALSE')]);

            DB::table('experiencias')
                ->where('usuario_id', $usuario->id_usuario)
                ->update(['es_publico' => DB::raw('FALSE')]);

            DB::table('participaciones')
                ->where('id_usuario', $usuario->id_usuario)
                ->whereNull('deleted_at')
                ->update(['visibilidad' => 'privado']);

            $usuario->tokens()->delete();
        });
    }
}
