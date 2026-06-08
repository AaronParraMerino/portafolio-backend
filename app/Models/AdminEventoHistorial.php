<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_actor_id', 'id_usuario');
    }
}
