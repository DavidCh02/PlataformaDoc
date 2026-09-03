<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportWordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('docs.create') ?? false;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:doc,docx', 'max:20480'],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
        ];
    }
}
