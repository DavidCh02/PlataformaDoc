<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $document = $this->route('document');

        if (! $user || ! $document instanceof Document) {
            return false;
        }

        return $user->can('update', $document);
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
        ];
    }
}
