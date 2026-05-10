<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Habilidad extends Model
{
    protected $table = 'habilidades';
    protected $primaryKey = 'id_habilidad';

    protected $fillable = [
        'nombre',
        'nombre_normalizado',
        'tipo',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function habilidadesUsuario()
    {
        return $this->hasMany(HabilidadUsuario::class, 'habilidad_id', 'id_habilidad');
    }
}