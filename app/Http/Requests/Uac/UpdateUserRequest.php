<?php
namespace App\Http\Requests\Uac;

use Illuminate\Foundation\Http\FormRequest;

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
            'email' => ['required', 'email', "unique:users,email,{$userId}"],
            'full_name' => ['required', 'string', 'max:255'],
            'roles' => ['required', 'array', 'exists:roles,id'],
        ];
    }
}