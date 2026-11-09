<?php

namespace Tests\Feature\Commercial;

use App\Models\JobTitle;
use App\Support\ErpNavigation;

class IdentityJobTitleTest extends CommercialTestCase
{
    public function test_the_name_card_shows_the_job_title(): void
    {
        $user = $this->officer();
        $title = JobTitle::query()->create(['job_title_name' => 'Senior Accountant']);
        $user->employee->update(['job_title_id' => $title->id]);

        $this->assertSame('Senior Accountant', app(ErpNavigation::class)->build($user->fresh(), 'leave')['identity']['role']);
        $this->actingAs($user->fresh())->get(route('leave.home'))->assertOk()->assertSee('Senior Accountant');
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk()->assertSee('Senior Accountant');
    }

    public function test_a_login_without_an_employee_record_falls_back_to_the_role(): void
    {
        $user = $this->userWithoutEmployee('900020', ['commercial_officer']);

        $this->assertSame($user->displayRoleNames(), app(ErpNavigation::class)->build($user->fresh(), 'leave')['identity']['role']);
    }
}
