<?php

namespace App\Enums;

/**
 * A member of staff's grade. The one place the allowed grades live: the staff form, the import, the reports and the
 * leave entitlement all read it, and a grade fixes the employee's category (so the category is never stored twice).
 *
 *   Junior Gd. Level 1..6  -> Junior Staff
 *   Charwoman              -> Contract (contract workers: no leave)
 *   Snr. Gd. Level 1..4    -> Senior Staff
 *   Mgt. Gd. Level 1..4    -> Management
 *
 * The backing value is what is stored in employees.grade.
 */
enum StaffGrade: string
{
    case JuniorLevel1 = 'Junior Gd. Level 1';
    case JuniorLevel2 = 'Junior Gd. Level 2';
    case JuniorLevel3 = 'Junior Gd. Level 3';
    case JuniorLevel4 = 'Junior Gd. Level 4';
    case JuniorLevel5 = 'Junior Gd. Level 5';
    case JuniorLevel6 = 'Junior Gd. Level 6';
    case Charwoman = 'Charwoman';
    case SeniorLevel1 = 'Snr. Gd. Level 1';
    case SeniorLevel2 = 'Snr. Gd. Level 2';
    case SeniorLevel3 = 'Snr. Gd. Level 3';
    case SeniorLevel4 = 'Snr. Gd. Level 4';
    case ManagementLevel1 = 'Mgt. Gd. Level 1';
    case ManagementLevel2 = 'Mgt. Gd. Level 2';
    case ManagementLevel3 = 'Mgt. Gd. Level 3';
    case ManagementLevel4 = 'Mgt. Gd. Level 4';

    public const CATEGORY_JUNIOR = 'Junior Staff';

    public const CATEGORY_SENIOR = 'Senior Staff';

    public const CATEGORY_MANAGEMENT = 'Management';

    public const CATEGORY_CONTRACT = 'Contract';

    /** Categories that existed before grades. They stay valid for staff who have no grade yet. */
    public const LEGACY_CATEGORIES = ['Senior Management', 'Charwoman'];

    public function category(): string
    {
        return match (true) {
            $this->isJunior() => self::CATEGORY_JUNIOR,
            $this === self::Charwoman => self::CATEGORY_CONTRACT,
            $this->isSenior() => self::CATEGORY_SENIOR,
            default => self::CATEGORY_MANAGEMENT,
        };
    }

    /** The level within the grade scale (1-6 junior, 1-4 senior and management), or null for Charwoman. */
    public function level(): ?int
    {
        return $this === self::Charwoman ? null : (int) substr($this->value, -1);
    }

    public function isJunior(): bool
    {
        return str_starts_with($this->value, 'Junior');
    }

    public function isSenior(): bool
    {
        return str_starts_with($this->value, 'Snr.');
    }

    public function isManagement(): bool
    {
        return str_starts_with($this->value, 'Mgt.');
    }

    /** Junior levels 1-3: the only grades whose annual leave depends on years of service. */
    public function isJuniorLower(): bool
    {
        return $this->isJunior() && $this->level() <= 3;
    }

    /** Contract workers (Charwoman) have no leave. */
    public function isLeaveEligible(): bool
    {
        return $this !== self::Charwoman;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $grade) => $grade->value, self::cases());
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return [self::CATEGORY_JUNIOR, self::CATEGORY_SENIOR, self::CATEGORY_MANAGEMENT, self::CATEGORY_CONTRACT];
    }

    /** Every category an employee may carry: the four above plus the pre-grade ones that remain valid without a grade. */
    public static function allCategories(): array
    {
        return [...self::categories(), 'Senior Management'];
    }

    /** The category a legacy (pre-grade) category value belongs to in reports. */
    public static function reportCategory(?string $category): ?string
    {
        return match ($category) {
            'Senior Management' => self::CATEGORY_MANAGEMENT,
            'Charwoman' => self::CATEGORY_CONTRACT,
            default => $category,
        };
    }

    /**
     * Reads a grade the way people type it: any case, with or without the dots, "Gd"/"Grade", "Level"/"Lvl"/"L",
     * "Jnr"/"Junior", "Snr"/"Senior", "Mgt"/"Mgmt"/"Management". Null for blank input and for anything that isn't a
     * real grade (including a level that doesn't exist, such as Snr. Gd. Level 5).
     */
    public static function fromInput(?string $value): ?self
    {
        $text = strtolower(trim((string) $value));
        $text = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));

        if ($text === '') {
            return null;
        }

        if (in_array($text, ['charwoman', 'charwomen', 'char woman', 'char women'], true)) {
            return self::Charwoman;
        }

        if (! preg_match('/^(junior|jnr|jr|jun|senior|snr|sr|management|mgmt|mgt|mgr)\s*(?:gd|grade)?\s*(?:level|lvl|lv|l)?\s*(\d)$/', $text, $matches)) {
            return null;
        }

        $prefix = match ($matches[1]) {
            'junior', 'jnr', 'jr', 'jun' => 'Junior Gd. Level ',
            'senior', 'snr', 'sr' => 'Snr. Gd. Level ',
            default => 'Mgt. Gd. Level ',
        };

        return self::tryFrom($prefix.$matches[2]);
    }
}
