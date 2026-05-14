<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ColaboradorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $colaboradorId = $this->route('id');

        return [
            'usuario_id' => 'nullable|integer|exists:usuarios,id|unique:colaboradores,usuario_id,' . $colaboradorId,
            'nombres' => 'required|string|max:150',
            'apellidos' => 'required|string|max:150',
            'tipo_id' => 'nullable|string|max:20',
            'identificacion' => 'required|string|max:50|unique:colaboradores,identificacion,' . $colaboradorId,
            'cargo' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'fecha_ingreso' => 'nullable|date',
            'fecha_retiro' => 'nullable|date',
            'contrato' => 'nullable|string|max:100',
            'estado' => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'nombres.required' => 'Los nombres son obligatorios.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'identificacion.required' => 'La identificación es obligatoria.',
            'identificacion.unique' => 'Esta identificación ya está registrada.',
            'usuario_id.unique' => 'Este usuario ya está asociado a otro colaborador.',
            'usuario_id.exists' => 'El usuario seleccionado no existe.',
        ];
    }
}
