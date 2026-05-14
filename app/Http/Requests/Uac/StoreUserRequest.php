<?php

namespace App\Http\Requests\Uac;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->whereIn('name', $this->assignableRoleNames()))],
        ];
    }

    protected function assignableRoleNames(): array
    {
        $query = Role::query()
            ->whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE]);

        if (! $this->user()?->hasRoles(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN)) {
            $query->whereNotIn('name', [User::ROLE_ADMIN, User::ROLE_ICT_TEAM]);
        }

        return $query->pluck('name')->all();
    }
}
