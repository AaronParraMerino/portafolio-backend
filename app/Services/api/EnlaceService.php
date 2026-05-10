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

    //services para actualizar un enlace de un usuario
    public function update(int $userId, int $idEnlace, array $data)
    {
        $enlace = Enlace::where('id_usuario', $userId)
                        ->where('id_enlace', $idEnlace)
                        ->firstOrFail();

        $enlace->update([
            'nombre'      => $data['nombre'] ?? $enlace->nombre,
            'link'        => $data['link'] ?? $enlace->link,
            'descripcion' => $data['descripcion'] ?? $enlace->descripcion,
        ]);

        return $enlace;
    }

    //services para editar la visibilidad de un enlace de un usuario
    public function updateVisibility(int $userId, int $idEnlace, bool $es_visible)
    {
        $bool = $es_visible ? 'TRUE' : 'FALSE';

        DB::statement("
            UPDATE enlaces 
            SET es_visible = $bool 
            WHERE id_enlace = :idEnlace 
            AND id_usuario = :userId
        ", [
            'idEnlace' => $idEnlace,
            'userId' => $userId
        ]);

        return DB::table('enlaces')
            ->where('id_enlace', $idEnlace)
            ->first();
    }


    //services para editar todas las visibilidades de los enlaces de un usuario
    public function updateAllVisibility(int $userId, bool $es_visible)
    {
        $bool = $es_visible ? 'TRUE' : 'FALSE';

        $updated = DB::update("
            UPDATE enlaces
            SET es_visible = $bool
            WHERE id_usuario = ?
        ", [$userId]);

        return [
            'success' => $updated > 0
        ];
    }


}