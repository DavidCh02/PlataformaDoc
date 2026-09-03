<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class SyncDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('docs.edit_realtime') ?? false;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'delta' => ['nullable', 'string', 'max:1048576', 'required_without:content'],
            'content' => ['nullable', 'string', 'max:10485760', 'required_without:delta'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $delta = $this->input('delta');

            if (is_string($delta) && base64_decode($delta, true) === false) {
                $validator->errors()->add('delta', 'El delta Yjs debe estar codificado en Base64 válido.');
            }
        });
    }
}
