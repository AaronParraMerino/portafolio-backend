<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventoInscripcion extends Model
{
    use HasFactory;

    protected $table = 'evento_inscripciones';
    protected $primaryKey = 'id_inscripcion';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'evento_id',
        'usuario_id',
        'estado',
        'fecha_inscripcion',
        'fecha_desinscripcion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inscripcion' => 'datetime',
            'fecha_desinscripcion' => 'datetime',
        ];
    }

    public function evento()
    {
        return $this->belongsTo(
            AdminEvento::class,
            'evento_id',
            'id_evento'
        );
    }

    public function usuario()
    {
        return $this->belongsTo(
            Usuario::class,
            'usuario_id',
            'id_usuario'
        );
    }
}