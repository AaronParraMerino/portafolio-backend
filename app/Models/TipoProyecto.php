<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoProyecto extends Model
{
    protected $table = 'tipos_proyecto';

    protected $primaryKey = 'id_tipo_proyecto';

    protected $fillable = [
        'nombre',
        'descripcion',
        'icono_url',
        'orden',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}