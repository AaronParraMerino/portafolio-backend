<?php

namespace App\Profile\Mock;

class ProfileMock
{
    private static array $usuarios = [
        [
            'id_usuario' => 1,
            'nombre' => 'Aaron',
            'apellido' => 'Parra',
            'correo' => 'aaron@ejemplo.com',
            'telefono' => '+591 70000000',
        ],
    ];

    private static array $perfiles = [
        [
            'id_perfil' => 1,
            'usuario_id' => 1,
            'biografia' => 'Desarrollador apasionado por crear soluciones digitales.',
            'ciudad' => 'Cochabamba',
            'pais' => 'Bolivia',
            'foto_perfil' => null,
            'foto_fondo' => null,
            'es_publico' => true,
        ],
    ];

    private static array $enlaces = [
        [
            'id_enlaceP' => 1,
            'usuario_id' => 1,
            'tipo' => 'github',
            'url' => 'https://github.com/AaronParraMerino',
            'etiqueta_custom' => null,
            'visible' => true,
        ],
        [
            'id_enlaceP' => 2,
            'usuario_id' => 1,
            'tipo' => 'linkedin',
            'url' => 'https://linkedin.com',
            'etiqueta_custom' => null,
            'visible' => true,
        ],
    ];

    private static array $visibilidad = [
        ['usuario_id' => 1, 'campo' => 'email', 'visible' => true],
        ['usuario_id' => 1, 'campo' => 'telefono', 'visible' => false],
        ['usuario_id' => 1, 'campo' => 'proyectos', 'visible' => true],
        ['usuario_id' => 1, 'campo' => 'pais', 'visible' => true],
        ['usuario_id' => 1, 'campo' => 'ciudad', 'visible' => true],
    ];

    public static function usuarios(): array
    {
        return self::$usuarios;
    }

    public static function perfiles(): array
    {
        return self::$perfiles;
    }

    public static function enlaces(): array
    {
        return self::$enlaces;
    }

    public static function visibilidad(): array
    {
        return self::$visibilidad;
    }

    public static function setUsuarios(array $data): void
    {
        self::$usuarios = $data;
    }

    public static function setPerfiles(array $data): void
    {
        self::$perfiles = $data;
    }

    public static function setVisibilidad(array $data): void
    {
        self::$visibilidad = $data;
    }

}