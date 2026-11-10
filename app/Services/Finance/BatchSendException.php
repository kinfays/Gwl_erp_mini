<?php

namespace App\Services\Finance;

use Illuminate\Validation\ValidationException;

/**
 * A batch could not be sent: nothing was sent, and every voucher that failed is listed with why. It is a ValidationException (the
 * screens already show those, under the `batch` key) that also carries the structured list.
 */
class BatchSendException extends ValidationException
{
    /** @var list<array{pv_id: int, pv_no: string, reasons: list<string>}> */
    public array $failures = [];

    /**
     * @param  list<array{pv_id: int, pv_no: string, reasons: list<string>}>  $failures
     */
    public static function for(array $failures): self
    {
        $lines = array_map(fn (array $failure) => 'PV '.$failure['pv_no'].': '.implode(' ', $failure['reasons']), $failures);

        /** @var self $exception */
        $exception = static::withMessages(['batch' => ['The batch was not sent. Fix these vouchers, or take them out of the batch:', ...$lines]]);
        $exception->failures = $failures;

        return $exception;
    }
}
