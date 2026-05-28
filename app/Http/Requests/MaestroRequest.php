<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaestroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'nombre' => 'required|string|max:255',
            'campo' => 'required|string|max:100',
            'habilitado' => 'required|string|in:Y,N',
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules = [
                'nombre' => 'sometimes|string|max:255',
                'campo' => 'sometimes|string|max:100',
                'habilitado' => 'sometimes|string|in:Y,N',
            ];
        }

        return $rules;
    }
}
