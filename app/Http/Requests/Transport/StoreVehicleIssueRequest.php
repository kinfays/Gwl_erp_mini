<?php

namespace App\Http\Requests\Transport;

use App\Models\VehicleIssue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('issue_type') && ! $this->has('issue_types')) {
            $this->merge(['issue_types' => [$this->input('issue_type')]]);
        }
    }

    public function rules(): array
    {
        return [
            'issue_types' => ['required', 'array', 'min:1'],
            'issue_types.*' => ['required', 'string', Rule::in(VehicleIssue::ISSUE_TYPES)],
            'severity' => ['required', 'string', Rule::in(VehicleIssue::SEVERITIES)],
            'description' => ['required', 'string', 'max:5000'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ];
    }
}
