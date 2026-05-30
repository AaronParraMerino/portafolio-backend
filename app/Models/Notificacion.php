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
        'id_usuario_actor',
        'modulo',
        'contexto_tipo',
        'contexto_referencia',
        'grupo_titulo',
        'tipo',
        'mensaje',
    ];

    public function usuarioActor()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_actor', 'id_usuario');
    }

    public function usuarios()
    {
        return $this->belongsToMany(
            Usuario::class,
            'notificacion_usuario',
            'id_notificacion',
            'id_usuario'
        )
        ->withPivot([
            'id_notificacion_usuario',
            'leido_en',
        ])
        ->withTimestamps();
    }

    public function notificacionUsuarios()
    {
        return $this->hasMany(
            NotificacionUsuario::class,
            'id_notificacion',
            'id_notificacion'
        );
    }

    public function scopeDelModulo($query, string $modulo)
    {
        return $query->where('modulo', $modulo);
    }

    public function scopeDelTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeDelContexto($query, ?string $contextoTipo, ?string $contextoReferencia)
    {
        return $query
            ->when($contextoTipo, function ($q) use ($contextoTipo) {
                $q->where('contexto_tipo', $contextoTipo);
            })
            ->when($contextoReferencia, function ($q) use ($contextoReferencia) {
                $q->where('contexto_referencia', $contextoReferencia);
            });
    }

    public function marcarComoLeidaParaUsuario(int $idUsuario): bool
    {
        return $this->notificacionUsuarios()
            ->where('id_usuario', $idUsuario)
            ->whereNull('leido_en')
            ->update([
                'leido_en' => now(),
            ]) > 0;
    }
}