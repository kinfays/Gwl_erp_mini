<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\Employee;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one place that decides which blog articles a user may read and which regions' blogs they may write to. The feed,
 * the article page, the cover image route, the manage list and the publishing service all call it, so they cannot
 * disagree; never filter by region in Blade.
 *
 * The rule is deliberately plain: a region's blog belongs to that region.
 *  - Reading: every member of staff reads the PUBLISHED articles of THEIR OWN region (employees.region_id), and nothing
 *    else. No Head Office or manager exception. (Head Office is a district whose staff carry that district's region_id,
 *    so they read the blog of that region, as the region id is the only thing compared.)
 *  - Writing: a holder of blog.manage_posts (the PR Officer) reads and writes every article, drafts included, of their own region only.
 *  - super_admin is not a member of staff (it has no employee record or region): it sees and writes every region.
 *  - A login with no employee record, or an employee with no region, sees nothing.
 */
class BlogVisibility
{
    public const PERMISSION_MANAGE = 'blog.manage_posts';

    public function can(User $user, string $slug): bool
    {
        return $user->hasRoles('super_admin') || $user->hasPermission($slug);
    }

    public function employeeOf(User $user): ?Employee
    {
        return $user->employee ?? $user->employeeByStaffId;
    }

    public function regionIdOf(User $user): ?int
    {
        $regionId = $this->employeeOf($user)?->region_id;

        return $regionId !== null ? (int) $regionId : null;
    }

    public function seesAllRegions(User $user): bool
    {
        return $user->hasRoles('super_admin');
    }

    /** Holds the permission AND has a region to write to (or is super_admin, who picks one). */
    public function canManage(User $user): bool
    {
        return $this->can($user, self::PERMISSION_MANAGE)
            && ($this->seesAllRegions($user) || $this->regionIdOf($user) !== null);
    }

    /** May write articles to this region's blog. */
    public function canManageIn(User $user, int $regionId): bool
    {
        return $this->canManage($user)
            && ($this->seesAllRegions($user) || $this->regionIdOf($user) === $regionId);
    }

    public function canManagePost(User $user, BlogPost $post): bool
    {
        return $this->canManageIn($user, (int) $post->region_id);
    }

    /** Whether the region is one the user belongs to (or any region, for super_admin). */
    public function inRegion(User $user, int $regionId): bool
    {
        return $this->seesAllRegions($user) || $this->regionIdOf($user) === $regionId;
    }

    /** Readers: published articles of their region. The managers of the region also read its drafts. */
    public function canRead(User $user, BlogPost $post): bool
    {
        if (! $this->inRegion($user, (int) $post->region_id)) {
            return false;
        }

        return $post->isPublished() || $this->canManagePost($user, $post);
    }

    /** The published articles the user may read. */
    public function scopeFeed(Builder $query, User $user): Builder
    {
        return $this->limitToRegions($query->published(), $user);
    }

    /** Every article, drafts included, of the regions the user manages; nothing for someone who cannot manage. */
    public function scopeManageable(Builder $query, User $user): Builder
    {
        if (! $this->canManage($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $this->limitToRegions($query, $user);
    }

    /**
     * The regions to offer in a picker: every region for super_admin, otherwise only the user's own.
     *
     * @return Collection<int, Region>
     */
    public function regionsFor(User $user): Collection
    {
        if ($this->seesAllRegions($user)) {
            return Region::query()->orderBy('region_name')->get();
        }

        $regionId = $this->regionIdOf($user);

        return $regionId === null
            ? collect()
            : Region::query()->whereKey($regionId)->get();
    }

    protected function limitToRegions(Builder $query, User $user): Builder
    {
        if ($this->seesAllRegions($user)) {
            return $query;
        }

        // No region: the impossible id keeps the query valid and empty.
        return $query->where($query->qualifyColumn('region_id'), $this->regionIdOf($user) ?? 0);
    }
}
