<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificacionUsuario extends Model
{
    use HasFactory;

    protected $table = 'notificacion_usuario';
    protected $primaryKey = 'id_notificacion_usuario';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_notificacion',
        'id_usuario',
        'leido_en',
    ];

    protected function casts(): array
    {
        return [
            'leido_en' => 'datetime',
        ];
    }

    public function notificacion()
    {
        return $this->belongsTo(
            Notificacion::class,
            'id_notificacion',
            'id_notificacion'
        );
    }

    public function usuario()
    {
        return $this->belongsTo(
            Usuario::class,
            'id_usuario',
            'id_usuario'
        );
    }

    public function scopePendientes($query)
    {
        return $query->whereNull('leido_en');
    }

    public function scopeLeidas($query)
    {
        return $query->whereNotNull('leido_en');
    }

    public function scopeDelUsuario($query, int $idUsuario)
    {
        return $query->where('id_usuario', $idUsuario);
    }

    public function marcarComoLeida(): bool
    {
        if ($this->leido_en !== null) {
            return true;
        }

        return $this->update([
            'leido_en' => now(),
        ]);
    }
}