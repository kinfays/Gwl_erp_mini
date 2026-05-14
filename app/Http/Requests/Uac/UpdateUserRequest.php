<?php
namespace App\Http\Requests\Uac;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');
        $actor = $this->user();

        if (
            $actor
            && $target instanceof User
            && $actor->is($target)
            && $actor->hasRoles(User::ROLE_ICT_TEAM)
            && ! $actor->hasRoles(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN)
        ) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
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
