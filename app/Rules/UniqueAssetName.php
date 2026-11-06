<?php

namespace App\Rules;

use App\Services\Assets\AssetRecordService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "An asset with this name already exists." Case-insensitive, ignoring surrounding spaces, across every device
 * category (assets, phones and network share one table). Pass the id of the record being edited to ignore it.
 */
class UniqueAssetName implements ValidationRule
{
    public function __construct(protected ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) !== '' && AssetRecordService::nameTaken($value, $this->ignoreId)) {
            $fail(AssetRecordService::DUPLICATE_NAME_MESSAGE);
        }
    }
}
