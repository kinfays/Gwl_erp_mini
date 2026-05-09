<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $employee = $this->user()->employee ?? $this->user()->employeeByStaffId;

        $emailRules = [
            'required',
            'string',
            'lowercase',
            'email',
            'max:255',
            Rule::unique(User::class, 'email')->ignore($this->user()->id),
        ];

        if ($employee) {
            $emailRules[] = Rule::unique(Employee::class, 'email')->ignore($employee->id);
        }

        return [
            'email' => $emailRules,
        ];
    }
}
