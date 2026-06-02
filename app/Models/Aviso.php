<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Aviso extends Model
{
    use HasFactory;

    protected $table = 'avisos';
    protected $primaryKey = 'id_aviso';

    public $incrementing = true;
    protected $keyType = 'int';

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_INACTIVO = 'inactivo';
    public const ESTADO_ELIMINADO = 'eliminado';

    public const PRIORIDAD_BAJA = 'baja';
    public const PRIORIDAD_NORMAL = 'normal';
    public const PRIORIDAD_ALTA = 'alta';
    public const PRIORIDAD_CRITICA = 'critica';

    protected $fillable = [
        'id_usuario_actor',
        'tipo',
        'titulo',
        'mensaje',
        'visible_desde',
        'visible_hasta',
        'estado',
        'prioridad',
    ];

    protected $casts = [
        'visible_desde' => 'datetime',
        'visible_hasta' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function usuarioActor()
    {
        return $this->belongsTo(
            Usuario::class,
            'id_usuario_actor',
            'id_usuario'
        );
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', self::ESTADO_ACTIVO);
    }

    public function scopeInactivos($query)
    {
        return $query->where('estado', self::ESTADO_INACTIVO);
    }

    public function scopeEliminados($query)
    {
        return $query->where('estado', self::ESTADO_ELIMINADO);
    }

    public function scopeDelTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeConPrioridad($query, string $prioridad)
    {
        return $query->where('prioridad', $prioridad);
    }

    public function scopeVisibles($query)
    {
        return $query
            ->where('estado', self::ESTADO_ACTIVO)
            ->where(function ($q) {
                $q->whereNull('visible_desde')
                    ->orWhere('visible_desde', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('visible_hasta')
                    ->orWhere('visible_hasta', '>=', now());
            });
    }

    public function estaVisible(): bool
    {
        if ($this->estado !== self::ESTADO_ACTIVO) {
            return false;
        }

        if ($this->visible_desde && $this->visible_desde->isFuture()) {
            return false;
        }

        if ($this->visible_hasta && $this->visible_hasta->isPast()) {
            return false;
        }

        return true;
    }
}
