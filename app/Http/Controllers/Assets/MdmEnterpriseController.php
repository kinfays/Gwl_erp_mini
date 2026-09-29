<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Services\Assets\Mdm\EnterpriseService;
use App\Services\Assets\Mdm\Exceptions\MdmException;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The browser half of the one-time enterprise bootstrap. Google redirects the admin here with ?enterpriseToken=…;
 * creating the enterprise is a POST (with CSRF) rather than a side effect of the GET, so a crafted link cannot
 * bind the ERP to somebody else's enterprise. super_admin only (see routes/web.php).
 */
class MdmEnterpriseController extends Controller
{
    use EnforcesModuleAccess;

    public function callback(Request $request): View
    {
        $this->enforceModule($request, 'assets');

        $token = (string) $request->query('enterpriseToken', '');

        return view('assets.mdm.enterprise', [
            'stage' => $token === '' ? 'missing' : 'confirm',
            'enterpriseToken' => $token,
            'enterpriseName' => null,
            'error' => null,
        ]);
    }

    public function create(Request $request, EnterpriseService $enterprise): View
    {
        $this->enforceModule($request, 'assets');

        $validated = $request->validate(['enterpriseToken' => ['required', 'string', 'max:2000']]);

        try {
            $name = $enterprise->completeSignup($validated['enterpriseToken']);
        } catch (MdmException $e) {
            return view('assets.mdm.enterprise', [
                'stage' => 'error',
                'enterpriseToken' => $validated['enterpriseToken'],
                'enterpriseName' => null,
                'error' => $e->getMessage(),
            ]);
        }

        return view('assets.mdm.enterprise', [
            'stage' => 'done',
            'enterpriseToken' => '',
            'enterpriseName' => $name,
            'error' => null,
        ]);
    }
}
