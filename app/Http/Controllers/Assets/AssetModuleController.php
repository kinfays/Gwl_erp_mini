<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetAudit;
use Illuminate\View\View;

class AssetModuleController extends Controller
{
    public function home(): View
    {
        return view('assets.home');
    }

    public function show(IctAsset $asset): View
    {
        return view('assets.show', ['asset' => $asset]);
    }

    public function summary(): View
    {
        return view('assets.summary');
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

    public function employee(Employee $employee): View
    {
        return view('assets.employee', ['employee' => $employee]);
    }

    public function audits(): View
    {
        return view('assets.audits.index');
    }

    public function audit(IctAssetAudit $audit): View
    {
        return view('assets.audits.show', ['audit' => $audit]);
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

    public function settingsManufacturers(): View
    {
        return view('assets.settings.manufacturers');
    }

    public function settingsModels(): View
    {
        return view('assets.settings.models');
    }

    public function settingsReplacementPolicy(): View
    {
        return view('assets.settings.replacement-policy');
    }

    public function settingsIpRanges(): View
    {
        return view('assets.settings.ip-ranges');
    }
}

