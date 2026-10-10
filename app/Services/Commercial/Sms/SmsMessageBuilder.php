<?php

namespace App\Services\Commercial\Sms;

/**
 * The text of a message: which merge fields it uses, its encoding and how many message parts (and so credits) it costs, and the
 * merged text of one customer. A message with merge fields is built IN MEMORY at send time from the stored TEMPLATE and is never
 * stored or logged.
 *
 * Parts: GSM-7 text is 160 characters in one part and 153 per part when it takes several; a character outside GSM-7 turns the
 * whole message into Unicode (UCS-2): 70 / 67. A few GSM-7 characters ({ } [ ] \ ^ ~ | and the euro sign) count twice.
 * With merge fields the count uses the longest value each field may take, so the estimate is on the safe side; the real parts of
 * each message are counted when it is built.
 */
class SmsMessageBuilder
{
    public const FIELDS = ['name', 'account_no', 'balance'];

    /** The longest each merge field is taken to be for an estimate. */
    private const WIDEST = ['name' => 30, 'account_no' => 12, 'balance' => 18];

    private const GSM7 = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM7_EXTENDED = "^{}\\[~]|€\f";

    /**
     * What a template uses and costs.
     *
     * @return array{fields: list<string>, personalised: bool, unknown: list<string>, encoding: string, segments: int, length: int}
     */
    public function inspect(string $body): array
    {
        preg_match_all('/\{([a-z_]+)\}/i', $body, $matches);
        $used = array_values(array_unique(array_map('strtolower', $matches[1])));
        $unknown = array_values(array_diff($used, self::FIELDS));
        $fields = array_values(array_intersect($used, self::FIELDS));

        $widest = $this->render($body, array_map(fn (string $field) => str_repeat($field === 'balance' ? '9' : ($field === 'account_no' ? '1' : 'n'), self::WIDEST[$field]), array_combine(self::FIELDS, self::FIELDS)));
        $count = $this->count($widest);

        return ['fields' => $fields, 'personalised' => $fields !== [], 'unknown' => $unknown, 'encoding' => $count['encoding'], 'segments' => $count['segments'], 'length' => $count['length']];
    }

    /**
     * Encoding, length and parts of a finished text.
     *
     * @return array{encoding: string, segments: int, length: int}
     */
    public function count(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($chars === []) {
            return ['encoding' => 'gsm7', 'segments' => 1, 'length' => 0];
        }

        $gsm = true;
        $septets = 0;

        foreach ($chars as $char) {
            if (str_contains(self::GSM7, $char)) {
                $septets++;
            } elseif (str_contains(self::GSM7_EXTENDED, $char)) {
                $septets += 2;
            } else {
                $gsm = false;

                break;
            }
        }

        if ($gsm) {
            return ['encoding' => 'gsm7', 'segments' => $septets <= 160 ? 1 : (int) ceil($septets / 153), 'length' => $septets];
        }

        // UCS-2 counts UTF-16 code units: a character outside the basic plane takes two.
        $units = array_sum(array_map(fn (string $char) => strlen(mb_convert_encoding($char, 'UTF-16BE', 'UTF-8')) / 2, $chars));

        return ['encoding' => 'ucs2', 'segments' => $units <= 70 ? 1 : (int) ceil($units / 67), 'length' => (int) $units];
    }

    /**
     * The merged text for one customer. Unknown {fields} are left as they are (the template is validated before it is saved).
     *
     * @param  array<string, string>  $values  field => text
     */
    public function render(string $body, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/i', fn (array $m) => $values[strtolower($m[1])] ?? $m[0], $body);
    }

    /** "GHS 1,234.50" (owed), "GHS 20.00 credit" (a credit balance) from a balance in pesewas. Plain ASCII: the cedi sign is not in GSM-7. */
    public static function money(int $pesewas): string
    {
        $amount = 'GHS '.number_format(abs($pesewas) / 100, 2);

        return $pesewas < 0 ? $amount.' credit' : $amount;
    }
}
