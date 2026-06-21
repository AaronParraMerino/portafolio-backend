<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMensaje extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'chat_mensajes';
    protected $primaryKey = 'id_chat_mensaje';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_chat',
        'id_usuario_emisor',
        'tipo',
        'contenido',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class, 'id_chat', 'id_chat');
    }

    public function emisor()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_emisor', 'id_usuario');
    }

    public function lecturas()
    {
        return $this->hasMany(ChatLectura::class, 'ultimo_mensaje_leido_id', 'id_chat_mensaje');
    }
}
