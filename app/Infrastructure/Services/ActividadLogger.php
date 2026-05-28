<?php

namespace App\Infrastructure\Services;

use App\Models\ActividadLog;

class ActividadLogger
{
    public static function log(
        int $usuarioId,
        string $tipo, // created, updated, deleted, login
        string $descripcion,
        ?string $modeloType = null,
        ?int $modeloId = null
    ): void {
        ActividadLog::create([
            'usuario_id' => $usuarioId,
            'tipo' => $tipo,
            'descripcion' => $descripcion,
            'modelo_type' => $modeloType,
            'modelo_id' => $modeloId,
        ]);
    }

    public static function created(int $usuarioId, string $descripcion, ?string $modeloType = null, ?int $modeloId = null): void
    {
        self::log($usuarioId, 'created', $descripcion, $modeloType, $modeloId);
    }

    public static function updated(int $usuarioId, string $descripcion, ?string $modeloType = null, ?int $modeloId = null): void
    {
        self::log($usuarioId, 'updated', $descripcion, $modeloType, $modeloId);
    }

    public static function deleted(int $usuarioId, string $descripcion, ?string $modeloType = null, ?int $modeloId = null): void
    {
        self::log($usuarioId, 'deleted', $descripcion, $modeloType, $modeloId);
    }

    public static function login(int $usuarioId, string $email): void
    {
        self::log($usuarioId, 'login', "Inicio de sesión: {$email}");
    }
}
