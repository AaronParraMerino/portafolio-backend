<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminEventoHistorial extends Model
{
    protected $table = 'admin_evento_historial';
    protected $primaryKey = 'id_historial';

    protected $fillable = [
        'usuario_actor_id',
        'accion',
        'entidad_tipo',
        'entidad_id',
        'titulo',
        'descripcion',
        'tipo',
        'estado',
        'destino',
        'channels',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'metadata' => 'array',
        ];
    }
}
