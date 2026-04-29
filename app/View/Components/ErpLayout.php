<?php

namespace App\View\Components;

use App\Support\ErpNavigation;
use Illuminate\View\Component;
use Illuminate\View\View;

class ErpLayout extends Component
{
    public function __construct(
        public string $module,
        public string $title = 'ERP Portal',
    ) {
    }

    public function render(): View
    {
        $navigation = app(ErpNavigation::class)->build(auth()->user(), $this->module);

        return view('layouts.erp', [
            'navigation' => $navigation,
            'module' => $this->module,
            'title' => $this->title,
        ]);
    }
}
