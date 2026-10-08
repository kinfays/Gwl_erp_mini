<?php

namespace App\Models\Concerns;

use App\Services\HealthSafety\HealthSafetySettings;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The computed state of a piece of equipment (fire extinguisher, first aid kit): the first state in states() that
 * applies wins, and "ok" means none does. Each state is written ONCE as a pair, an SQL condition for the query scopes and
 * the same test in PHP for state(), side by side, so the register filters, the overview counts and the accessor cannot
 * disagree. A test runs both over a grid of fixtures to keep it that way.
 *
 * The state is computed, never stored, so it cannot go stale; only status (in service, decommissioned...) is stored.
 * Items that are not evaluated (decommissioned, discharged) have no state and are left out of every attention list.
 *
 * The check baseline is the last check, or the day the item was added if it was never checked, so a brand-new item is not
 * "overdue" until a full interval has passed.
 */
trait HasEquipmentState
{
    public const STATE_OK = 'ok';

    /**
     * In first-match-wins order: name => ['sql' => fn (Builder): void, 'php' => fn (self): bool, 'label' => string].
     * Every SQL condition must be NULL-safe (guard nullable columns with whereNotNull), because it is also negated to
     * exclude the earlier states.
     *
     * @return array<string, array{sql: \Closure, php: \Closure, label: string}>
     */
    abstract public static function states(): array;

    /** Whether the item's lifecycle status means it is evaluated at all. */
    abstract public function isEvaluated(): bool;

    /** Restrict a query to the lifecycle statuses that are evaluated. */
    abstract public function scopeEvaluated(Builder $query): Builder;

    /** The state as a name, or null for an item that is not evaluated (decommissioned, discharged). */
    public function state(): ?string
    {
        foreach (static::states() as $name => $state) {
            if (($state['php'])($this)) {
                return $name;
            }
        }

        return $this->isEvaluated() ? self::STATE_OK : null;
    }

    public static function stateLabel(?string $state): string
    {
        if ($state === null) {
            return 'Not evaluated';
        }

        return $state === self::STATE_OK ? 'OK' : (static::states()[$state]['label'] ?? $state);
    }

    /** @return list<string> every state name a query can be filtered by, "ok" last */
    public static function stateNames(): array
    {
        return [...array_keys(static::states()), self::STATE_OK];
    }

    /** Items whose state is exactly $state: that state applies and no earlier one does. */
    public function scopeWithState(Builder $query, string $state): Builder
    {
        $states = static::states();

        if ($state !== self::STATE_OK && ! isset($states[$state])) {
            throw new InvalidArgumentException("Unknown equipment state [{$state}].");
        }

        if ($state === self::STATE_OK) {
            $query->evaluated();

            foreach ($states as $other) {
                static::excluding($query, $other['sql']);
            }

            return $query;
        }

        $query->where(fn (Builder $inner) => ($states[$state]['sql'])($inner));

        foreach ($states as $name => $other) {
            if ($name === $state) {
                break;
            }

            static::excluding($query, $other['sql']);
        }

        return $query;
    }

    /**
     * AND NOT (condition). Written as a nested where with the "and not" boolean rather than whereNot(), so the closure
     * receives an Eloquent builder and can use the model's scopes.
     */
    protected static function excluding(Builder $query, \Closure $condition): void
    {
        $query->where(fn (Builder $inner) => $condition($inner), null, null, 'and not');
    }

    /** Items in any state but "ok": the list a site walk-round and the overview work from. */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $any) {
            foreach (static::states() as $state) {
                $any->orWhere(fn (Builder $inner) => ($state['sql'])($inner));
            }
        });
    }

    /** SQL for "not checked for the interval": the baseline is the last check, else the day it was added. */
    protected static function checkOverdueSql(Builder $query): void
    {
        $table = $query->getModel()->getTable();
        $cutoff = today()->subDays((int) HealthSafetySettings::value('hs_check_interval_days'))->toDateString();

        $query->whereRaw("COALESCE(DATE({$table}.last_checked_on), DATE({$table}.created_at)) <= ?", [$cutoff]);
    }

    protected function isCheckOverdue(): bool
    {
        $baseline = ($this->last_checked_on ?? $this->created_at)?->copy()->startOfDay();

        return $baseline !== null && $baseline->lte(today()->subDays((int) HealthSafetySettings::value('hs_check_interval_days')));
    }

    /** The date the next check falls due: a full interval after the baseline. */
    public function nextCheckDue(): \Illuminate\Support\Carbon
    {
        return ($this->last_checked_on ?? $this->created_at)->copy()->startOfDay()->addDays((int) HealthSafetySettings::value('hs_check_interval_days'));
    }

    protected static function checkFailedSql(Builder $query): void
    {
        $query->whereNotNull($query->getModel()->getTable().'.last_check_result')
            ->where($query->getModel()->getTable().'.last_check_result', 'fail');
    }

    /** SQL for "date column falls today up to $days days ahead". */
    protected static function dateWithin(Builder $query, string $column, int $days): void
    {
        $table = $query->getModel()->getTable();

        $query->whereNotNull("{$table}.{$column}")
            ->whereDate("{$table}.{$column}", '>=', today()->toDateString())
            ->whereDate("{$table}.{$column}", '<=', today()->addDays($days)->toDateString());
    }

    /** SQL for "date column is before today". */
    protected static function dateBeforeToday(Builder $query, string $column): void
    {
        $table = $query->getModel()->getTable();

        $query->whereNotNull("{$table}.{$column}")
            ->whereDate("{$table}.{$column}", '<', today()->toDateString());
    }

    /** PHP twin of dateWithin(). */
    protected static function phpWithin(mixed $date, int $days): bool
    {
        return $date !== null && $date->copy()->startOfDay()->gte(today()) && $date->copy()->startOfDay()->lte(today()->addDays($days));
    }

    /** PHP twin of dateBeforeToday(). */
    protected static function phpBeforeToday(mixed $date): bool
    {
        return $date !== null && $date->copy()->startOfDay()->lt(today());
    }
}
