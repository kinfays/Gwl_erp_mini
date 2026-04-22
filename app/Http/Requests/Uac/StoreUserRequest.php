<?php

namespace App\Http\Requests\Uac;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

   /*-- public function rules(): array
    {
        return [
            'staff_id' => ['required', 'string', 'max:50', 'unique:users,staff_id'],
            'email' => ['required', 'email', 'unique:users,email'],
            'full_name' => ['required', 'string', 'max:255'],
            'roles' => ['required', 'array', 'exists:roles,id'],
        ];
    } */

        public function rules(): array
{
    return [
        'employee_id' => ['required', 'exists:employees,id'],
        'roles' => ['required', 'array', 'exists:roles,id'],
    ];
}
}
