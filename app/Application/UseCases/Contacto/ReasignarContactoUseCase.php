<?php

namespace App\Application\UseCases\Contacto;

use App\Models\Contacto;
use App\Models\Oportunidad;
use App\Models\Seguimiento;

class ReasignarContactoUseCase
{
    /**
     * Reasigna un contacto a otra entidad.
     * Si ya existe un contacto con el mismo email en la entidad destino,
     * retorna conflicto a menos que se pida merge explícitamente.
     *
     * @return array{success: bool, data?: Contacto, conflict?: array, message?: string}
     */
    public function execute(int $contactoId, int $nuevaEntidadId, bool $merge = false): array
    {
        $contacto = Contacto::find($contactoId);

        if (! $contacto) {
            return ['success' => false, 'message' => 'Contacto no encontrado.'];
        }

        // Misma entidad → no-op
        if ($contacto->entidad_id == $nuevaEntidadId) {
            return [
                'success' => true,
                'data' => $contacto,
                'message' => 'El contacto ya pertenece a esta entidad.',
            ];
        }

        // Buscar conflicto de email en la entidad destino
        if ($contacto->email_contacto) {
            $existente = Contacto::where('entidad_id', $nuevaEntidadId)
                ->where('email_contacto', $contacto->email_contacto)
                ->where('id', '!=', $contactoId)
                ->first();

            if ($existente && ! $merge) {
                return [
                    'success' => false,
                    'conflict' => [
                        'id' => $existente->id,
                        'nombres' => $existente->nombres,
                        'apellidos' => $existente->apellidos,
                        'email_contacto' => $existente->email_contacto,
                    ],
                    'message' => "Ya existe un contacto con el email \"{$contacto->email_contacto}\" en la entidad destino.",
                ];
            }

            if ($existente && $merge) {
                $this->mergeContactos($existente, $contacto);
            }
        }

        // Actualizar entidad_id
        $contacto->update(['entidad_id' => $nuevaEntidadId]);

        return [
            'success' => true,
            'data' => $contacto->fresh(),
            'message' => 'Contacto reasignado exitosamente.',
        ];
    }

    /**
     * Fusiona dos contactos: transfiere seguimientos y oportunidades del existente al nuevo,
     * y elimina el contacto que quedó duplicado.
     */
    private function mergeContactos(Contacto $existente, Contacto $nuevo): void
    {
        // Transferir seguimientos del contacto existente al nuevo
        Seguimiento::where('contacto_id', $existente->id)
            ->update(['contacto_id' => $nuevo->id]);

        // Transferir oportunidades del contacto existente al nuevo
        Oportunidad::where('contacto_id', $existente->id)
            ->update(['contacto_id' => $nuevo->id]);

        // Eliminar el contacto duplicado
        $existente->delete();
    }
}
