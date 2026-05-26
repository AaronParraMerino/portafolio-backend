<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminEvento extends Model
{
    protected $table = 'admin_eventos';
    protected $primaryKey = 'id_evento';

    protected $fillable = [
        'usuario_creador_id',
        'usuario_actualizador_id',
        'titulo',
        'descripcion',
        'tipo',
        'estado',
        'fecha_inicio',
        'fecha_fin',
        'programado_para',
        'ubicacion',
        'cupo',
        'inscritos',
        'interesados',
        'espera',
        'asistieron',
        'no_asistieron',
        'target_mode',
        'channels',
        'segments',
        'target_selections',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
            'programado_para' => 'datetime',
            'channels' => 'array',
            'segments' => 'array',
            'target_selections' => 'array',
        ];
    }

    public function comunicaciones()
    {
        return $this->hasMany(AdminEventoComunicacion::class, 'evento_id', 'id_evento');
    }
}
