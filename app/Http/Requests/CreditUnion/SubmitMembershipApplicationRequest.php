<?php

namespace App\Http\Requests\CreditUnion;

use Illuminate\Foundation\Http\FormRequest;

class SubmitMembershipApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user && ($user->hasRoles('super_admin') || $user->hasPermission('credit_union.apply_membership'));
    }

    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
