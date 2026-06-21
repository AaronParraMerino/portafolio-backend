<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Denuncia extends Model
{
    use HasFactory;

    protected $table = 'denuncias';
    protected $primaryKey = 'id_denuncia';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_denunciante',
        'asunto',
        'motivo',
        'detalle',
        'evidencia',
        'metadata',
        'estado',
        'id_usuario_revisor',
        'respuesta_admin',
        'revisado_at',
    ];

    protected $casts = [
        'evidencia' => 'array',
        'metadata' => 'array',
        'revisado_at' => 'datetime',
    ];

    public function denunciante()
    {
        return $this->belongsTo(Usuario::class, 'id_denunciante', 'id_usuario');
    }

    public function revisor()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_revisor', 'id_usuario');
    }
}
