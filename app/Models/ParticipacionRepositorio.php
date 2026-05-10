<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParticipacionRepositorio extends Model
{
    protected $table = 'participacion_repositorios';
    protected $primaryKey = 'id_participacion_repositorio';

    protected $fillable = [
        'id_participacion',
        'id_proyecto_repositorio',
        'validado',
        'es_propietario',
        'validado_at',
    ];

    protected function casts(): array
    {
        return [
            'validado' => 'boolean',
            'es_propietario' => 'boolean',
            'validado_at' => 'datetime',
        ];
    }
}
