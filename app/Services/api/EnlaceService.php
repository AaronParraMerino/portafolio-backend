<?php

namespace App\Services\api;

use App\Models\Usuario;
use App\Models\Enlace;
use Illuminate\Support\Facades\DB;

class EnlaceService
{
    //Services para agregar un enlace de un usuario
    public function create(int $userId, array $data)
    {
        return Enlace::create([
            'id_usuario'   => $userId,
            'nombre'       => $data['nombre'],
            'link'         => $data['link'],
            'descripcion'  => $data['descripcion'] ?? null,
            'es_visible' => DB::raw('true')
        ]);
    }

    //services para obtener los enlaces de un usuario
    public function getByUser(int $userId)
    {
        return Enlace::where('id_usuario', $userId)->get();
    }

    //services para eliminar un enlace de un usuario
    public function delete(int $userId, int $idEnlace)
    {
        $enlace = Enlace::where('id_usuario', $userId)
                        ->where('id_enlace', $idEnlace)
                        ->firstOrFail();

        return $enlace->delete();
    }

}