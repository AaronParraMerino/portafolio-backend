<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventoPersonal extends Model
{
    protected $table = 'eventos_personales';
    protected $primaryKey = 'id_evento';

    protected $fillable = [
        'usuario_id',
        'titulo',
        'descripcion',
        'fecha',
        'hora',
        'tipo',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }
}
