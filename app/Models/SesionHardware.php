<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesionHardware extends Model
{
    protected $table = 'sesion_hardware';

    protected $fillable = [
        'id_rastreo_base',
        'gpu_renderer',
        'cpu_nucleos',
        'ram_estimada',
        'hdr_soporte',
        'bateria_nivel',
        'uuid_persistente',
        'consentimiento_fecha',
        'consentimiento_version',
        'consentimiento_ip',
        'consentimiento_user_agent',
        'consentimiento_firma',
    ];

    protected function casts(): array
    {
        return [
            'cpu_nucleos' => 'integer',
            'ram_estimada' => 'decimal:2',
            'hdr_soporte' => 'boolean',
            'consentimiento_fecha' => 'datetime',
        ];
    }
}
