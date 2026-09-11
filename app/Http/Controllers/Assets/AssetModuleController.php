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

    public function assets(): View
    {
        return view('assets.assets');
    }

    public function phones(): View
    {
        return view('assets.phones');
    }

    public function network(): View
    {
        return view('assets.network');
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

    public function settingsModels(): View
    {
        return view('assets.settings.models');
    }

    public function settingsIpRanges(): View
    {
        return view('assets.settings.ip-ranges');
    }
}

