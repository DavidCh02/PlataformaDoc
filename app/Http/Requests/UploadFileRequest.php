<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('files.upload') ?? false;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,png,jpg,jpeg,docx',
                'max:20480',
            ],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
        ];
    }
}
