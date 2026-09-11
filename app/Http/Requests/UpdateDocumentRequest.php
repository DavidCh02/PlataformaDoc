<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if ($user->can('docs.edit_realtime')) {
            return true;
        }

        $documentId = $this->route('document');
        if ($documentId) {
            return \App\Models\Document::where('id', $documentId)->where('user_id', $user->id)->exists();
        }

        return false;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string', 'max:10485760'],
        ];
    }
}
