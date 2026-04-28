<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UsoTecnologia extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'uso_tecnologias';

    protected $primaryKey = 'id_uso_tecnologia';

    protected $fillable = [
        'id_proyecto',
        'id_tecnologia',
        'version_usada',
        'porcentaje_uso',
        'es_principal',
        'es_visible',
    ];

    protected $casts = [
        'porcentaje_uso' => 'float',
        'es_principal' => 'boolean',
        'es_visible' => 'boolean',
    ];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'id_proyecto', 'id_proyecto');
    }

    public function tecnologia()
    {
        return $this->belongsTo(Tecnologia::class, 'id_tecnologia', 'id_tecnologia');
    }
}