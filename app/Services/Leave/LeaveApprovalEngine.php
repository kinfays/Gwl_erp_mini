<?php

namespace App\Services\Leave;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\ManagerRecommendation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Mail\Leave\LeaveRecommendedMail;
use App\Mail\Leave\LeaveSubmittedMail;
use App\Mail\Leave\LeaveApprovedMail;
use App\Notifications\LeaveRecommendedNotification;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveApprovalEngine
{
    public function __construct(
        protected LeaveEntitlementResolver $entitlementResolver
    ) {}

    /* ======================================================
     * SUBMIT LEAVE
     * ==================================================== */

    public function submit(LeaveRequest $request): LeaveRequest
    {
        if ($request->leave_status !== LeaveStatus::Planned) {
            throw ValidationException::withMessages([
                'leave' => 'Only planned leave can be submitted.',
            ]);
        }

        // Casual leave restriction
        if ($request->leave_type === LeaveType::Casual) {
            $annualBalance = LeaveBalance::where('staff_id', $request->requester_id)
                ->where('leave_type', LeaveType::Annual)
                ->where('current_year', $request->request_year)
                ->first();

            if ($annualBalance && $annualBalance->remaining_days > 0) {
                throw ValidationException::withMessages([
                    'leave_type' =>
                        'Casual leave cannot be requested while Annual Leave balance exists.',
                ]);
            }
        }

        $manager = $this->resolveFirstLevelManager($request->requester);

        $request->update([
            'manager_id' => $manager?->id,
            'leave_status' => LeaveStatus::PendingApproval,
            'manager_recommendation' => ManagerRecommendation::Pending,
        ]);

        // TODO: dispatch LeaveSubmittedMail + notification
        if ($manager?->email) {
            Mail::to($manager->email)
                ->send(new LeaveSubmittedMail($request));
        }
        if ($manager?->user || $manager?->userByStaffId) {
            $managerUser = $manager->user ?? $manager->userByStaffId;
            $managerUser->notify(new LeaveRecommendedNotification($request));
        }

        return $request->refresh();
    }

    /* ======================================================
     * RECOMMEND
     * ==================================================== */

    public function recommend(
        LeaveRequest $request,
        Employee $manager,
        ?string $comments = null
    ): LeaveRequest {
        if ($request->leave_status !== LeaveStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'leave' => 'Leave request is not pending approval.',
            ]);
        }

        if ($request->manager_id !== $manager->id) {
            throw ValidationException::withMessages([
                'manager' => 'You are not authorised to recommend this request.',
            ]);
        }

        $request->update([
            'manager_comments' => $comments,
            'manager_recommendation' => ManagerRecommendation::Recommended,
        ]);

        $chiefManager = $request->requester->chiefManager();

        if ($chiefManager?->email) {
            Mail::to($chiefManager->email)
                ->send(new LeaveRecommendedMail($request));
        }

        $chiefManagerUser = $chiefManager?->user ?? $chiefManager?->userByStaffId;

        if ($chiefManagerUser) {
            $chiefManagerUser->notify(new LeaveRecommendedNotification($request));
        }

        return $request->refresh();
    }

    /* ======================================================
     * FINAL APPROVAL
     * ==================================================== */

    public function approve(
        LeaveRequest $request,
        Employee $approver,
        ?string $comments = null
    ): LeaveRequest {
        if ($request->leave_status !== LeaveStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'leave' => 'Leave request is not pending approval.',
            ]);
        }

        if ($request->manager_recommendation !== ManagerRecommendation::Recommended) {
            throw ValidationException::withMessages([
                'leave' => 'Leave must be recommended before final approval.',
            ]);
        }

        DB::transaction(function () use ($request, $approver, $comments) {

            $employee = $request->requester;
            $year = $request->request_year;

            $entitlement = $this->entitlementResolver->resolve(
                $employee,
                $request->leave_type,
                $year
            );

            $balance = LeaveBalance::firstOrCreate(
                [
                    'staff_id' => $employee->id,
                    'leave_type' => $request->leave_type,
                    'current_year' => $year,
                ],
                [
                    'entitle_days' => $entitlement['entitle_days'],
                    'carry_over_days' => $entitlement['carry_over_days'],
                    'carry_over_expired_date' =>
                        $entitlement['carry_over_expired_date'],
                    'used_days' => 0,
                    'remaining_days' =>
                        $entitlement['total_available_days'],
                    'region_id' => $employee->region_id,
                    'district_id' => $employee->district_id,
                ]
            );

            if ($balance->remaining_days < $request->total_days_applied) {
                throw ValidationException::withMessages([
                    'leave' => 'Insufficient leave balance.',
                ]);
            }

            $balance->deduct($request->total_days_applied);

            $request->update([
                'leave_status' => LeaveStatus::Approved,
                'approved_by_id' => $approver->id,
                'chiefManager_comments' => $comments,
            ]);
        });

        // TODO: LeaveApprovedMail to HR (region‑scoped), CC requester + manager


// HR emails (region scoped)
$hrEmails = Employee::whereHas('roles', fn ($q) =>
        $q->where('name', 'HR Officer')
    )
    ->when($request->region_id, fn ($q) =>
        $q->where('region_id', $request->region_id)
    )
    ->pluck('email')
    ->toArray();

Mail::to($hrEmails)
    ->cc([
        $request->requester->email,
        $request->manager?->email,
    ])
    ->send(new LeaveApprovedMail($request));

        return $request->refresh();
    }

    /* ======================================================
     * DENY
     * ==================================================== */

    public function deny(
        LeaveRequest $request,
        Employee $actor,
        ?string $reason = null
    ): LeaveRequest {
        if (! in_array($request->leave_status, [
            LeaveStatus::PendingApproval,
        ], true)) {
            throw ValidationException::withMessages([
                'leave' => 'Only pending requests can be denied.',
            ]);
        }

        $request->update([
            'leave_status' => LeaveStatus::Denied,
            'manager_comments' => $reason,
        ]);

        return $request->refresh();
    }

    /* ======================================================
     * RE‑OPEN
     * ==================================================== */

    public function reopen(LeaveRequest $request, Employee $employee): LeaveRequest
    {
        if ($request->leave_status !== LeaveStatus::Denied) {
            throw ValidationException::withMessages([
                'leave' => 'Only denied leave can be re‑opened.',
            ]);
        }

        if ($request->requester_id !== $employee->id) {
            throw ValidationException::withMessages([
                'leave' => 'You can only re‑open your own leave request.',
            ]);
        }

        $request->update([
            'leave_status' => LeaveStatus::Planned,
            'manager_recommendation' => ManagerRecommendation::Pending,
            'manager_comments' => null,
            'chiefManager_comments' => null,
        ]);

        return $request->refresh();
    }

    /* ======================================================
     * APPROVAL CHAIN RESOLUTION
     * ==================================================== */

    protected function resolveFirstLevelManager(Employee $employee): ?Employee
    {
        // HEAD OFFICE
        if ($employee->isHeadOffice()) {
            return $employee->unitManager()
                ?? $employee->departmentManager();
        }

        // REGION
        if ($employee->isRegion()) {
            return $employee->departmentManager();
        }

        // DISTRICT
        if ($employee->isDistrict()) {
            return $employee->districtManager();
        }

        return null;
    }
}
