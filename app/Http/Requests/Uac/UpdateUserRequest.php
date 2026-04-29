<?php
namespace App\Http\Requests\Uac;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'roles' => ['required', 'array'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('name', '!=', 'super_admin'))],
        ];
    }
}
