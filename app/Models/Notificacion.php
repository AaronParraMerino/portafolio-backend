<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notificacion extends Model
{
    use HasFactory;

    protected $table = 'notificaciones';
    protected $primaryKey = 'id_notificacion';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_usuario_destino',
        'id_usuario_actor',
        'tipo',
        'modulo',
        'titulo',
        'contenido',
        'referencia_tipo',
        'referencia_id',
        'data',
        'event_key',
        'leida_en',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'leida_en' => 'datetime',
        ];
    }

    public function usuarioDestino()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_destino', 'id_usuario');
    }

    public function usuarioActor()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_actor', 'id_usuario');
    }

    public function scopePendientes($query)
    {
        return $query->whereNull('leida_en');
    }

    public function scopeLeidas($query)
    {
        return $query->whereNotNull('leida_en');
    }

    public function scopeDelUsuario($query, int $idUsuario)
    {
        return $query->where('id_usuario_destino', $idUsuario);
    }

    public function marcarComoLeida(): bool
    {
        if ($this->leida_en !== null) {
            return true;
        }

        return $this->update([
            'leida_en' => now(),
        ]);
    }
}