<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminEventoComunicacion extends Model
{
    protected $table = 'admin_evento_comunicaciones';
    protected $primaryKey = 'id_comunicacion';

    protected $fillable = [
        'evento_id',
        'usuario_creador_id',
        'usuario_actualizador_id',
        'titulo',
        'cuerpo',
        'tipo',
        'estado',
        'urgencia',
        'destinatarios',
        'programado_para',
        'enviado_en',
        'audiences',
        'channels',
        'segments',
        'pinned',
    ];

    protected function casts(): array
    {
        return [
            'programado_para' => 'datetime',
            'enviado_en' => 'datetime',
            'audiences' => 'array',
            'channels' => 'array',
            'segments' => 'array',
            'pinned' => 'boolean',
        ];
    }

    public function evento()
    {
        return $this->belongsTo(AdminEvento::class, 'evento_id', 'id_evento');
    }
}
