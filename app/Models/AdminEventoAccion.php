<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminEventoAccion extends Model
{
    protected $table = 'admin_evento_acciones';
    protected $primaryKey = 'id_accion';

    protected $fillable = [
        'evento_id',
        'admin_id',
        'usuario_publicante_id',
        'accion',
        'estado_anterior',
        'estado_nuevo',
        'motivo',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function evento()
    {
        return $this->belongsTo(AdminEvento::class, 'evento_id', 'id_evento');
    }

    public function admin()
    {
        return $this->belongsTo(Usuario::class, 'admin_id', 'id_usuario');
    }

    public function publicante()
    {
        return $this->belongsTo(Usuario::class, 'usuario_publicante_id', 'id_usuario');
    }
}
