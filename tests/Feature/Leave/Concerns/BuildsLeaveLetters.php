<?php

namespace Tests\Feature\Leave\Concerns;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\LeaveApprovalRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Everything the approval-letter tests share: a fixed "today" (Wednesday 6 May 2026, the date of the company template), the
 * seeded roles and grants, the org from BuildsLeaveOrg, the standard District / Head Office / MD approval chains, and a
 * way to take a request all the way to final approval.
 */
trait BuildsLeaveLetters
{
    use BuildsLeaveOrg;

    protected function setUpLetterWorld(): void
    {
        Notification::fake();
        Mail::fake();
        Storage::fake(config('gwl.signature_disk'));
        config(['gwl.hr_analytics_cache_seconds' => 0]);
        $this->travelTo(Carbon::parse('2026-05-06 09:00'));

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, LeaveApprovalRolePermissionSeeder::class]);
        $this->buildOrg();
    }

    /**
     * A district applicant (Senior grade: 36 days, no compulsory deduction) with the people above them.
     *
     * @return array{applicant: Employee, manager: Employee, chief: Employee}
     */
    protected function districtChain(string $grade = 'Snr. Gd. Level 2', array $title = []): array
    {
        return [
            'applicant' => $this->staff('EMP001', $this->temaDistrict, $this->operations, grade: $grade),
            'manager' => $this->staff('DM001', $this->temaDistrict, $this->operations, ['district_manager']),
            'chief' => $this->staff('RCM001', $this->accraOffice, $this->operations, ['regional_chief_manager']),
        ];
    }

    /**
     * A Head Office applicant in a unit, with the unit manager and the chief manager of the department.
     *
     * @return array{applicant: Employee, manager: Employee, chief: Employee}
     */
    protected function headOfficeChain(string $grade = 'Snr. Gd. Level 2'): array
    {
        return [
            'applicant' => $this->staff('HOA001', $this->headOffice, $this->finance, unit: 'Payroll', grade: $grade),
            'manager' => $this->staff('HOM001', $this->headOffice, $this->finance, ['manager'], unit: 'Payroll'),
            'chief' => $this->staff('HOC001', $this->headOffice, $this->finance, ['chief_manager']),
        ];
    }

    /** Take $applicant's request through recommendation (when it has that stage) and the final approval. */
    protected function approveLeave(Employee $applicant, array $leave = [], ?Employee $recommender = null, ?Employee $approver = null, bool $applySignature = false): LeaveRequest
    {
        $request = $this->workflow()->submit($applicant, $this->leaveData($leave));

        if (! $request->is_single_stage) {
            $this->workflow()->recommend($recommender, $request->fresh(), null, true);
        }

        $this->workflow()->finalDecision($approver, $request->fresh(), null, true, $applySignature);

        return $request->fresh(['letter']);
    }

    /** A leave request that is already approved (for wording tests that don't need the workflow). */
    protected function approvedRequest(Employee $applicant, User $approver, array $attributes = []): LeaveRequest
    {
        $start = Carbon::parse($attributes['start_date'] ?? '2026-05-11');
        $end = Carbon::parse($attributes['end_date'] ?? '2026-05-15');
        $days = app(\App\Services\Leave\WorkingDaysCalculator::class)->workingDays($start, $end);

        return LeaveRequest::query()->create($attributes + [
            'requester_id' => $applicant->id,
            'leave_type' => 'Annual',
            'start_date' => $start,
            'end_date' => $end,
            'total_days_applied' => $days,
            'leave_details' => 'Rest',
            'manager_id' => $applicant->id,
            'manager_recommendation' => 'Recommended',
            'leave_status' => 'Approved',
            'is_single_stage' => true,
            'chief_user_id' => $approver->id,
            'approved_by_id' => $this->employeeOfUser($approver)->id,
            'request_year' => $start->year,
            'department_id' => $applicant->department_id,
            'region_id' => $applicant->region_id,
            'submitted_at' => now(),
            'decided_at' => now(),
        ]);
    }

    protected function employeeOfUser(User $user): Employee
    {
        return Employee::query()->findOrFail($user->employee_id);
    }

    /** A real PNG of "ink on white paper" (opaque white background, dark strokes). */
    protected function signaturePng(int $width = 400, int $height = 140, bool $blank = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        if (! $blank) {
            $ink = imagecolorallocate($image, 20, 20, 90);
            imagesetthickness($image, 4);
            imageline($image, 20, (int) ($height * 0.7), (int) ($width * 0.4), 20, $ink);
            imageline($image, (int) ($width * 0.4), 20, (int) ($width * 0.6), (int) ($height * 0.8), $ink);
            imageline($image, (int) ($width * 0.6), (int) ($height * 0.8), $width - 20, 30, $ink);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected function signatureDataUrl(): string
    {
        return 'data:image/png;base64,'.base64_encode($this->signaturePng());
    }

    protected function signatureUpload(string $name = 'signature.png', ?string $bytes = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes ?? $this->signaturePng());
    }
}
