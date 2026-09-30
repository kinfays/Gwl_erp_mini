<?php

namespace App\Http\Requests\Uac;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');
        $actor = $this->user();

        // A scoped ICT user never changes their own roles.
        return ! (
            $actor
            && $target instanceof User
            && $actor->is($target)
            && $actor->isScopedIct()
        );
    }

    /** See StoreUserRequest: role-level rules live in RoleAssignmentService / RoleGrantPolicy. */
    public function rules(): array
    {
        return [
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer'],
        ];
    }
}
