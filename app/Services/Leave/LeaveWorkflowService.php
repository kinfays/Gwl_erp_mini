<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeaveWorkflowService
{
    public function __construct(
        protected WorkingDaysCalculator $daysCalc,
        protected LeaveBalanceService $balances,
        protected LeaveApprovalChainResolver $chain,
        protected LeaveNotificationService $notify
    ) {}

    public function savePlanned(Employee $requester, array $data): LeaveRequest
    {
        return $this->createOrUpdate($requester, $data, 'Planned');
    }

    public function submit(Employee $requester, array $data): LeaveRequest
    {
        // Casual restriction: block if Annual remaining > 0
        if (($data['leave_type'] ?? '') === 'Casual') {
            $annualRemaining = $this->balances->getVirtualRemaining($requester, 'Annual', (int) now()->format('Y'));
            if ($annualRemaining > 0) {
                throw new \RuntimeException('Casual leave is not allowed while Annual leave balance is greater than 0.');
            }
        }

        $request = $this->createOrUpdate($requester, $data, 'Pending Approval');
        $this->notifyEmployee(
            $request->manager,
            'Leave approval needed',
            $requester->full_name.' submitted a '.$request->leave_type.' leave request.',
            route('leave.approvals'),
            ['type' => 'leave_submitted', 'leave_request_id' => $request->id]
        );

        return $request;

    }

    protected function createOrUpdate(Employee $requester, array $data, string $status): LeaveRequest
    {
        [$manager, $chief] = $this->chain->resolve($requester);

        $start = $data['start_date'];
        $end = $data['end_date'];

        $total = $this->daysCalc->workingDays(
            Carbon::parse($start),
            Carbon::parse($end)
        );

        $year = (int) Carbon::parse($start)->format('Y');

        return LeaveRequest::create([
            'requester_id' => $requester->id,
            'leave_type' => $data['leave_type'],
            'start_date' => $start,
            'end_date' => $end,
            'total_days_applied' => $total,
            'leave_details' => $data['leave_details'] ?? null,
            'manager_id' => $manager->id,
            'manager_comments' => null,
            'manager_recommendation' => 'Pending',
            'leave_status' => $status,
            'approved_by_id' => null,
            'chiefManager_comments' => null,
            'request_year' => $year,
            'department_id' => $requester->department_id,
            'region_id' => $requester->region_id,
            'file_attachment' => $data['file_attachment'] ?? null,
        ]);
    }

    public function recommend(Employee $managerActor, LeaveRequest $req, string $comments, bool $recommended): LeaveRequest
    {
        // Only the assigned manager can recommend
        if ($req->manager_id !== $managerActor->id) {
            throw new \RuntimeException('You are not allowed to recommend this request.');
        }

        $req->manager_comments = $comments;
        $req->manager_recommendation = $recommended ? 'Recommended' : 'Rejected';
        if ($recommended) {
            [$mgr, $chief] = $this->chain->resolve($req->requester);
            $this->notify->recommended($req, $chief->email);
        } else {
            $req->leave_status = 'Denied';
        }

        $req->save();

        if (! $recommended) {
            $this->notify->denied($req);
            $this->notifyEmployee(
                $req->requester,
                'Leave request rejected',
                'Your '.$req->leave_type.' leave request was rejected by your manager.',
                route('leave.my-history'),
                ['type' => 'leave_rejected', 'leave_request_id' => $req->id]
            );
        } else {
            $this->notifyEmployee(
                $chief,
                'Final leave approval needed',
                $req->requester->full_name."'s leave request has been recommended.",
                route('leave.approvals'),
                ['type' => 'leave_recommended', 'leave_request_id' => $req->id]
            );
        }

        return $req;
    }

    public function finalDecision(Employee $chiefActor, LeaveRequest $req, string $comments, bool $approve): LeaveRequest
    {
        // Determine expected chief approver for requester
        [$mgr, $chief] = $this->chain->resolve($req->requester);

        if ($chiefActor->id !== $chief->id) {
            throw new \RuntimeException('You are not allowed to approve/deny this request.');
        }

        if ($req->manager_recommendation !== 'Recommended') {
            throw new \RuntimeException('Request must be recommended before final approval.');
        }

        return DB::transaction(function () use ($approve, $comments, $req, $chiefActor) {
            $req->chiefManager_comments = $comments;
            $req->approved_by_id = $chiefActor->id;

            if (! $approve) {
                $req->leave_status = 'Denied';
                $req->save();
                $this->notify->denied($req);
                $this->notifyEmployee(
                    $req->requester,
                    'Leave request denied',
                    'Your '.$req->leave_type.' leave request was denied.',
                    route('leave.my-history'),
                    ['type' => 'leave_denied', 'leave_request_id' => $req->id]
                );

                return $req;
            }

            $req->leave_status = 'Approved';
            $req->save();

            // Create/fetch balance on approval & deduct days
            $balance = $this->balances->getOrCreateForApproval($req->requester, $req->leave_type, (int) $req->request_year);
            $this->balances->deduct($balance, (int) $req->total_days_applied);
            $hrEmails = User::query()
                ->whereHas('roles', fn ($r) => $r->whereIn('name', ['hr_headoffice', 'hr_region']))
                ->when($req->region_id, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('region_id', $req->region_id)))
                ->pluck('email')
                ->toArray();

            $this->notify->approved($req, $balance, $hrEmails);
            $this->notifyEmployee(
                $req->requester,
                'Leave request approved',
                'Your '.$req->leave_type.' leave request was approved.',
                route('leave.my-history'),
                ['type' => 'leave_approved', 'leave_request_id' => $req->id]
            );

            return $req;
        });
    }

    public function reopen(Employee $requester, LeaveRequest $req): LeaveRequest
    {
        if ($req->requester_id !== $requester->id) {
            throw new \RuntimeException('Only requester can reopen.');
        }

        if ($req->leave_status !== 'Denied') {
            throw new \RuntimeException('Only denied requests can be reopened.');
        }

        $req->leave_status = 'Planned';
        $req->manager_recommendation = 'Pending';
        $req->manager_comments = null;
        $req->chiefManager_comments = null;
        $req->approved_by_id = null;
        $req->save();

        return $req;
    }

    protected function notifyEmployee(Employee $employee, string $title, string $message, string $url, array $meta = []): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $user = User::query()
            ->where('employee_id', $employee->id)
            ->orWhere('staff_id', $employee->staff_id)
            ->first();

        $user?->notify(new GeneralDatabaseNotification(
            $title,
            $message,
            $url,
            'leave',
            $meta
        ));
    }
}
