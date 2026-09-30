<?php

namespace App\Http\Requests\Uac;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Which roles may be given, and to whom, is not decided here: RoleAssignmentService checks each submitted role
     * against the actor and the employee's location, and reports a refused one as `roles.<index>`.
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer'],
        ];
    }
}
