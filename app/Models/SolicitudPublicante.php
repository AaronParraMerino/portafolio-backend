<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudPublicante extends Model
{
    protected $table = 'publicante_solicitudes';
    protected $primaryKey = 'id_solicitud';

    protected $fillable = [
        'usuario_id',
        'admin_revisor_id',
        'documento',
        'telefono_actual',
        'telefono_referencia',
        'correo_respaldo',
        'organizacion',
        'cargo',
        'motivo',
        'experiencia',
        'enlaces',
        'estado',
        'motivo_revision',
        'revisada_en',
    ];

    protected function casts(): array
    {
        return [
            'revisada_en' => 'datetime',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }

    public function adminRevisor()
    {
        return $this->belongsTo(Usuario::class, 'admin_revisor_id', 'id_usuario');
    }
}
