<?php

namespace App\View\Components;

use App\Models\BlogPost;
use App\Services\Blog\BlogVisibility;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The standalone layout of the Regional Blog: its own masthead and category strip, no ERP sidebar or module tabs. It only
 * gathers what the chrome shows (the region's name, whether to offer "Manage"); which articles a page lists is decided by
 * BlogVisibility in the controller or component that renders inside it.
 */
class BlogLayout extends Component
{
    public function __construct(
        public string $title = 'Regional Blog',
        public ?string $active = null,
        public ?string $regionName = null,
        public ?string $description = null,
        public bool $wide = false,
    ) {}

    public function render(): View
    {
        $user = auth()->user();
        $visibility = app(BlogVisibility::class);

        $region = $this->regionName
            ?? ($user && ! $visibility->seesAllRegions($user) ? $visibility->regionsFor($user)->first()?->region_name : null);

        return view('layouts.blog', [
            'region' => $region,
            'canManage' => $user && $visibility->canManage($user),
            'categories' => BlogPost::CATEGORY_PLURALS,
            'user' => $user,
        ]);
    }
}
