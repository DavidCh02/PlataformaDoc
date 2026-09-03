<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncUserPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Un administrador no puede modificar su propio rol ni permisos.
        $target = $this->route('user');

        if ($target instanceof User && $this->user()?->id === $target->id) {
            return false;
        }

        return $this->user()?->can('users.manage') ?? false;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }
}
