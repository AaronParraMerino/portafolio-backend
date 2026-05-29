<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminEventoPlantilla extends Model
{
    protected $table = 'admin_evento_plantillas';
    protected $primaryKey = 'id_plantilla';

    protected $fillable = [
        'usuario_creador_id',
        'usuario_actualizador_id',
        'titulo',
        'cuerpo',
        'tipo',
        'channels',
        'payload',
        'usadas',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'payload' => 'array',
        ];
    }
}
