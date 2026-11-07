<?php

namespace App\Services\Finance;

use App\Models\FinSupplier;

/**
 * Finds suppliers whose name is ALMOST the same as a typed one ("ABC Trading Ltd." vs "ABC Trading Limited", a one-letter slip,
 * a shorter name that the longer one begins with). An exact match is not handled here: that is refused by the unique name key.
 *
 * It works on a loose key (upper case, punctuation to spaces, noise words such as LTD / LIMITED / ENTERPRISE / GHANA dropped) held
 * in memory, so the import can test thousands of rows against thousands of suppliers with one query. The result is a WARNING for
 * a person to judge, never a refusal, and it is kept here, in one place, so the screens, the form and the import agree.
 */
class SupplierMatcher
{
    /** Words that do not tell two businesses apart. */
    protected const NOISE = ['LTD', 'LIMITED', 'CO', 'COMPANY', 'ENT', 'ENTERPRISE', 'ENTERPRISES', 'GH', 'GHANA', 'INC', 'PLC', 'LLC', 'THE', 'AND'];

    /** @var array<int, array{id: int, name: string, loose: string, active: bool}> */
    protected array $entries = [];

    /** @param iterable<FinSupplier> $suppliers */
    public function __construct(iterable $suppliers = [])
    {
        foreach ($suppliers as $supplier) {
            $this->add($supplier->id, $supplier->name, (bool) $supplier->is_active);
        }
    }

    public function add(int $id, string $name, bool $active = true): void
    {
        $this->entries[$id] = ['id' => $id, 'name' => $name, 'loose' => self::loose($name), 'active' => $active];
    }

    /**
     * @return list<array{id: int, name: string, active: bool}> up to $limit suppliers that look like $name (never $exceptId)
     */
    public function matches(string $name, ?int $exceptId = null, int $limit = 5): array
    {
        $needle = self::loose($name);

        if ($needle === '') {
            return [];
        }

        $found = [];

        foreach ($this->entries as $entry) {
            if ($entry['id'] === $exceptId || $entry['loose'] === '') {
                continue;
            }

            if ($this->similar($needle, $entry['loose'])) {
                $found[] = ['id' => $entry['id'], 'name' => $entry['name'], 'active' => $entry['active']];

                if (count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    /** Upper case, punctuation to spaces, noise words dropped, spaces collapsed (the original words if nothing else is left). */
    public static function loose(string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_values(array_diff($words, self::NOISE));

        return implode(' ', $kept !== [] ? $kept : $words);
    }

    protected function similar(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $short = strlen($a) <= strlen($b) ? $a : $b;
        $long = $short === $a ? $b : $a;

        // "ABC TRADING" against "ABC TRADING CENTRE": the longer begins with the whole shorter name.
        if (mb_strlen($short) >= 6 && str_starts_with($long, $short.' ')) {
            return true;
        }

        // A slip of the keyboard in ONE word of a name with the same number of words. A short word must match exactly ("A
        // Traders" and "B Traders", "Fresh One" and "Fresh Two" are different businesses); a word of 5 to 7 letters may be one
        // letter off; a longer one two (a swapped pair of letters counts as two).
        $wordsA = explode(' ', $a);
        $wordsB = explode(' ', $b);

        if (count($wordsA) !== count($wordsB)) {
            return false;
        }

        $differing = 0;

        foreach ($wordsA as $index => $word) {
            $other = $wordsB[$index];

            if ($word === $other) {
                continue;
            }

            $length = max(strlen($word), strlen($other));
            $allowed = $length >= 8 ? 2 : ($length >= 5 ? 1 : 0);

            if ($allowed === 0 || levenshtein($word, $other) > $allowed || ++$differing > 1) {
                return false;
            }
        }

        return $differing === 1;
    }
}
