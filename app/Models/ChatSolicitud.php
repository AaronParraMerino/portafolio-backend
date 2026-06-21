<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatSolicitud extends Model
{
    use HasFactory;

    protected $table = 'chat_solicitudes';
    protected $primaryKey = 'id_chat_solicitud';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_chat',
        'id_solicitante',
        'id_destinatario',
        'estado',
        'mensaje_inicial',
        'expires_at',
        'cooldown_until',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'cooldown_until' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class, 'id_chat', 'id_chat');
    }

    public function solicitante()
    {
        return $this->belongsTo(Usuario::class, 'id_solicitante', 'id_usuario');
    }

    public function destinatario()
    {
        return $this->belongsTo(Usuario::class, 'id_destinatario', 'id_usuario');
    }
}
