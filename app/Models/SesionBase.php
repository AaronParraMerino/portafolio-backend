<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesionBase extends Model
{
    protected $table = 'sesion_base';
    protected $primaryKey = 'id_rastreo_interno';

    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'session_token',
        'ip_address',
        'isp_proveedor',
        'pais_codigo',
        'navegador_nombre',
        'navegador_version',
        'sistema_operativo',
        'es_movil',
        'resolucion_pantalla',
        'idioma_preferido',
        'zona_horaria',
        'fuente_url',
        'pagina_entrada',
        'fecha_ingreso',
        'ultima_actividad',
        'consentimiento_legal',
        'usuario_id',
        'personal_access_token_id',
        'token_recuperacion_id',
        'bitacora_id',
    ];

    protected function casts(): array
    {
        return [
            'es_movil' => 'boolean',
            'consentimiento_legal' => 'boolean',
            'fecha_ingreso' => 'datetime',
            'ultima_actividad' => 'datetime',
        ];
    }
}