<?php

namespace App\Services\Visitors;

use App\Models\Visitor;

class VisitorService
{
    public const CHECKOUT_SELF = 'self';
    public const CHECKOUT_RECEPTIONIST = 'receptionist';
    public const CHECKOUT_AUTO = 'auto';

    /**
     * Record a kiosk check-in, stamping a checkout code that is unique among
     * visitors still inside today.
     */
    public function checkIn(array $data): Visitor
    {
        return Visitor::create([
            ...$data,
            'checkout_code' => $this->makeCheckoutCode(),
            'check_in_at' => now(),
        ]);
    }

    public function checkOut(Visitor $visitor, string $checkedOutBy, ?string $signature = null): Visitor
    {
        $visitor->update([
            'signature' => $signature ?: $visitor->signature,
            'check_out_at' => now(),
            'checked_out_by' => $checkedOutBy,
        ]);

        return $visitor;
    }

    /**
     * Scheduled closing-time sweep: checks out everyone still inside today.
     */
    public function autoCheckOutToday(): int
    {
        return Visitor::query()
            ->whereNull('check_out_at')
            ->whereDate('check_in_at', today())
            ->update([
                'check_out_at' => now(),
                'checked_out_by' => self::CHECKOUT_AUTO,
                'updated_at' => now(),
            ]);
    }

    /**
     * Self-checkout looks visits up by (today, inside, code), so a 1-3 digit
     * code only has to be unique among visitors currently inside today.
     */
    public function makeCheckoutCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $digits = random_int(1, 3);
            $code = (string) random_int(10 ** ($digits - 1), (10 ** $digits) - 1);

            $exists = Visitor::query()
                ->today()
                ->inside()
                ->where('checkout_code', $code)
                ->exists();

            if (! $exists) {
                return $code;
            }
        }

        return (string) random_int(100, 999);
    }
}
