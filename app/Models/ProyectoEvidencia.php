<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProyectoEvidencia extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'proyecto_evidencias';

    protected $primaryKey = 'id_evidencia';

    protected $fillable = [
        'id_proyecto',
        'titulo',
        'descripcion',
        'tipo',
        'url',
        'archivo_path',
        'mime_type',
        'tamanio_bytes',
        'es_portada',
        'es_visible',
        'orden',
    ];

    protected $casts = [
        'es_portada' => 'boolean',
        'es_visible' => 'boolean',
    ];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'id_proyecto', 'id_proyecto');
    }
}