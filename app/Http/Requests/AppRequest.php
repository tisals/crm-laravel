<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $appId = $this->route('id');

        return [
            'slug' => 'required|string|max:50|regex:/^[a-z0-9-]+$/|unique:apps,slug,'.($appId ?? 'NULL'),
            'nombre' => 'required|string|max:100',
            'tipo' => 'required|in:internal,external,customer',
            'auth_type' => 'required|in:sanctum,api_key',
            'activo' => 'boolean',
            'descripcion' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'El slug solo puede contener letras minúsculas, números y guiones.',
            'slug.unique' => 'Ya existe una app con ese slug.',
            'slug.required' => 'El slug es obligatorio.',
            'nombre.required' => 'El nombre es obligatorio.',
            'tipo.in' => 'El tipo debe ser internal, external o customer.',
            'auth_type.in' => 'El tipo de auth debe ser sanctum o api_key.',
        ];
    }
}
