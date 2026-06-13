<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

class StoreMileageLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mileage_before' => ['nullable', 'integer', 'min:0'],
            'mileage_after' => ['required', 'integer', 'min:1'],
            'trip_date' => ['required', 'date'],
            'trip_purpose' => ['required', 'string', 'max:255'],
        ];
    }
}
