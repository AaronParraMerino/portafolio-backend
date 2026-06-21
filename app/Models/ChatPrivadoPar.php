<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatPrivadoPar extends Model
{
    use HasFactory;

    protected $table = 'chat_privado_pares';
    protected $primaryKey = 'id_chat_privado_par';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_chat',
        'id_usuario_menor',
        'id_usuario_mayor',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class, 'id_chat', 'id_chat');
    }

    public function usuarioMenor()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_menor', 'id_usuario');
    }

    public function usuarioMayor()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_mayor', 'id_usuario');
    }
}
