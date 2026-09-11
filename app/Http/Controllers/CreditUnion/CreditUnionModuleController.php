<?php

namespace App\Http\Controllers\CreditUnion;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreditUnion\SubmitMembershipApplicationRequest;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Services\CreditUnion\MemberRegistrationService;
use App\Services\CreditUnion\StatementService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CreditUnionModuleController extends Controller
{
    use EnforcesModuleAccess;

    public function home(Request $request): RedirectResponse
    {
        $this->enforceModule($request, Permission::MODULE_CREDIT_UNION);

        $user = $request->user();

        if ($user->hasRoles('super_admin') || $user->hasPermission('credit_union.manage_members')) {
            return redirect()->route('credit-union.members');
        }

        return redirect()->route('credit-union.apply');
    }

    public function apply(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_CREDIT_UNION);

        return view('credit-union.apply');
    }

    public function submitApplication(SubmitMembershipApplicationRequest $request, MemberRegistrationService $registration): RedirectResponse
    {
        $this->enforceModule($request, Permission::MODULE_CREDIT_UNION);

        $registration->submitSelfApplication($request->user(), $request->validated());

        return redirect()
            ->route('credit-union.apply')
            ->with('status', 'Your membership application has been submitted for approval.');
    }

    public function members(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_CREDIT_UNION);

        return view('credit-union.members');
    }

    public function applications(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_CREDIT_UNION);

        return view('credit-union.applications');
    }

    public function memberShow(Request $request, CreditUnionMember $member): View
    {
        $this->enforceModule($request, Permission::MODULE_CREDIT_UNION);

        return view('credit-union.member-show', ['member' => $member]);
    }

    public function memberStatementPdf(Request $request, CreditUnionMember $member, StatementService $statements)
    {
        $this->enforceModule($request, Permission::MODULE_CREDIT_UNION);

        $html = view('credit-union.exports.statement-pdf', $statements->payload($member))->render();

        $options = new Options;
        $options->set('isRemoteEnabled', true);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$statements->fileName($member).'"',
        ]);
    }
}
