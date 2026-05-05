<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tecnologia extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tecnologias';

    protected $primaryKey = 'id_tecnologia';

    protected $fillable = [
        'nombre',
        'tipo',
        'icono_url',
        'color',
        'descripcion',
    ];
}