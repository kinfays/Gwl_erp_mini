<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin wrappers around the MDM Livewire screens. Ids are passed through untouched (no implicit model binding):
 * each component resolves them through MdmAccessGuard so an id outside the viewer's region is a 404.
 */
class MdmModuleController extends Controller
{
    use EnforcesModuleAccess;

    public function devices(Request $request): View
    {
        $this->enforceModule($request, 'assets');

        return view('assets.mdm.devices');
    }

    public function device(Request $request, string $device): View
    {
        $this->enforceModule($request, 'assets');

        return view('assets.mdm.device', ['deviceId' => (int) $device]);
    }

    public function enroll(Request $request): View
    {
        $this->enforceModule($request, 'assets');

        return view('assets.mdm.enroll');
    }

    public function policies(Request $request): View
    {
        $this->enforceModule($request, 'assets');

        return view('assets.mdm.policies');
    }

    public function policyCreate(Request $request): View
    {
        $this->enforceModule($request, 'assets');

        return view('assets.mdm.policy-editor', ['policyId' => null]);
    }

    public function policyEdit(Request $request, string $policy): View
    {
        $this->enforceModule($request, 'assets');

        return view('assets.mdm.policy-editor', ['policyId' => (int) $policy]);
    }
}
