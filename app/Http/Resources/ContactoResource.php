<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entidad_id' => $this->entidad_id,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'area' => $this->area,
            'cargo' => $this->cargo,
            'tel_contacto' => $this->tel_contacto,
            'movil' => $this->movil,
            'email_contacto' => $this->email_contacto,
            'email_secundario' => $this->email_secundario,
            'rol' => $this->rol,
            'etapa' => $this->etapa,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
