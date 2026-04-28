<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Participacion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'participaciones';

    protected $primaryKey = 'id_participacion';

    protected $fillable = [
        'id_usuario',
        'id_proyecto',
        'rol',
        'descripcion_aporte',
        'es_propietario',
        'visibilidad',
        'estado_participacion',
        'fecha_inicio',
        'fecha_fin',
    ];

    protected $casts = [
        'es_propietario' => 'boolean',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'id_proyecto', 'id_proyecto');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}