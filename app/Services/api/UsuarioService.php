<?php

namespace App\Services\api;

use App\Models\Usuario;
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

    public function delete(Usuario $usuario): void
    {
        $usuario->delete();
    }
}