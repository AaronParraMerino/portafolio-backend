<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chat extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'chats';
    protected $primaryKey = 'id_chat';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'tipo',
        'estado',
        'id_usuario_creador',
        'nombre',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function creador()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_creador', 'id_usuario');
    }

    public function parPrivado()
    {
        return $this->hasOne(ChatPrivadoPar::class, 'id_chat', 'id_chat');
    }

    public function participantes()
    {
        return $this->hasMany(ChatParticipante::class, 'id_chat', 'id_chat');
    }

    public function mensajes()
    {
        return $this->hasMany(ChatMensaje::class, 'id_chat', 'id_chat');
    }

    public function solicitudes()
    {
        return $this->hasMany(ChatSolicitud::class, 'id_chat', 'id_chat');
    }

    public function invitaciones()
    {
        return $this->hasMany(ChatInvitacion::class, 'id_chat', 'id_chat');
    }

    public function lecturas()
    {
        return $this->hasMany(ChatLectura::class, 'id_chat', 'id_chat');
    }
}
