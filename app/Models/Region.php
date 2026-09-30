<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Region extends Model
{
    use HasFactory;

    /** Width of regions.letter_prefix (and letter_sn_counters.prefix). */
    public const LETTER_PREFIX_WIDTH = 10;

    protected $fillable = [
        'region_name',
        'letter_prefix',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** The initials of the name's words, upper-cased ("Greater Accra" -> "GA"); "REG" when the name has no words. */
    public static function deriveLetterPrefix(string $name): string
    {
        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->join('');

        return Str::substr($initials ?: 'REG', 0, self::LETTER_PREFIX_WIDTH);
    }

    /**
     * The serial-number prefix of this region. A region created without one (seeders, imports, fresh installs) gets
     * the derived initials, made unique by appending the region id on a clash, and keeps it from then on.
     *
     * The write only happens while the column is still null, so two requests deriving at once cannot overwrite each
     * other, and it runs in its own (savepoint) transaction so a unique-index clash cannot poison a caller's.
     */
    public function assignLetterPrefix(): string
    {
        if (filled($this->letter_prefix)) {
            return $this->letter_prefix;
        }

        $base = static::deriveLetterPrefix($this->region_name);
        $candidates = [
            $base,
            Str::substr($base, 0, self::LETTER_PREFIX_WIDTH - strlen((string) $this->getKey())).$this->getKey(),
        ];

        foreach ($candidates as $candidate) {
            if (static::query()->where('letter_prefix', $candidate)->whereKeyNot($this->getKey())->exists()) {
                continue;
            }

            try {
                DB::transaction(fn () => static::query()->whereKey($this->getKey())->whereNull('letter_prefix')->update(['letter_prefix' => $candidate]));
            } catch (UniqueConstraintViolationException) {
                continue; // another region took it between the check and the write
            }

            break;
        }

        $assigned = static::query()->whereKey($this->getKey())->value('letter_prefix');

        if (blank($assigned)) {
            throw new \RuntimeException("Could not assign a letter prefix to region {$this->getKey()}; set one on the Regions screen.");
        }

        $this->setAttribute('letter_prefix', $assigned);
        $this->syncOriginalAttribute('letter_prefix');

        return $assigned;
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActiveHierarchy($query)
    {
        return $query->with(['districts', 'employees']);
    }
}