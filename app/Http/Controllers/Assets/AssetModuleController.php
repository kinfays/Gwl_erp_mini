<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AssetModuleController extends Controller
{
    public function home(): View
    {
        return view('assets.home');
    }

    public function inventory(): View
    {
        return view('assets.inventory');
    }

    public function maintenance(): View
    {
        return view('assets.maintenance');
    }

    public function reports(): View
    {
        return view('assets.reports');
    }

    public function agent(): View
    {
        return view('assets.agent');
    }
}

