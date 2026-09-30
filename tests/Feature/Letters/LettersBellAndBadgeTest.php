<?php

namespace Tests\Feature\Letters;

use App\Models\Employee;
use App\Support\ErpNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

/**
 * A manager handed a letter is usually working in Leave, so the letters bell and a pending count on the Letters tab
 * must be on every ERP page for anyone with the Letters module - and not for anyone without it.
 */
class LettersBellAndBadgeTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $hrSec;

    protected Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLettersOrg();
        $this->hrSec = $this->letterStaff('HR001', $this->accraOffice);
        $this->manager = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);
    }

    private function page(Employee $employee, string $route)
    {
        return $this->actingAs($this->letterUserOf($employee))->get(route($route));
    }

    public function test_the_letters_bell_is_rendered_outside_the_letters_module_for_users_with_access(): void
    {
        foreach (['dashboard', 'leave.home', 'leave.apply', 'profile.edit'] as $route) {
            $this->page($this->manager, $route)->assertOk()->assertSeeHtml('class="letter-notify"');
        }

        // ... and still inside the module, as before.
        $this->page($this->manager, 'letters.home')->assertOk()->assertSeeHtml('class="letter-notify"');
    }

    public function test_the_letters_bell_is_not_rendered_for_users_without_the_letters_module(): void
    {
        $noLetters = $this->letterStaff('EMP01', $this->accraOffice, ['leave_applicant']);

        foreach (['dashboard', 'leave.home'] as $route) {
            $this->page($noLetters, $route)->assertOk()->assertDontSeeHtml('class="letter-notify"');
        }
    }

    public function test_the_bell_shows_a_handed_over_letter_on_a_page_outside_the_module(): void
    {
        $letter = $this->createLetter($this->hrSec, ['subject' => 'Look at this']);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->manager);

        $this->page($this->manager, 'leave.home')
            ->assertOk()
            ->assertSeeHtml('class="letter-notify"')
            ->assertSeeHtml('Letters notifications, 1 unread');
    }

    public function test_the_letters_tab_carries_the_pending_incoming_count(): void
    {
        $this->page($this->manager, 'dashboard')->assertOk()->assertDontSee('module-count');

        $letters = $this->createLetters($this->hrSec, 2);
        $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->manager, $letters->pluck('id')->all());
        $single = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($single, $this->hrSec, $this->manager);

        $this->page($this->manager, 'leave.home')
            ->assertOk()
            ->assertSeeHtml('class="module-count"')
            ->assertSeeInOrder(['Letters', 'Pending: ', '3']);

        // The sender has nothing to confirm.
        $this->page($this->hrSec, 'dashboard')->assertOk()->assertDontSee('module-count');

        $this->lettersWorkflow()->confirmHardcopy($single, $this->manager);
        $this->page($this->manager, 'leave.home')->assertSeeHtml('class="module-count"')->assertSeeInOrder(['Pending: ', '2']);

        $this->lettersWorkflow()->confirmHardcopies($this->manager, $letters->pluck('id')->all());
        $this->page($this->manager, 'leave.home')->assertDontSee('module-count');
    }

    public function test_the_badge_is_data_on_the_letters_module_entry_only(): void
    {
        $this->lettersWorkflow()->dispatch($this->createLetter($this->hrSec), $this->hrSec, $this->manager);

        $modules = collect(app(ErpNavigation::class)->build($this->letterUserOf($this->manager)->fresh(), 'leave')['modules']);

        $this->assertSame(1, $modules->firstWhere('slug', 'letters')['badge']);
        $this->assertArrayNotHasKey('badge', $modules->firstWhere('slug', 'leave'));
    }

    public function test_the_general_bell_is_untouched_and_still_excludes_letters_notifications(): void
    {
        $this->page($this->manager, 'dashboard')->assertOk()->assertSeeHtml('general-bell');
        $this->assertStringContainsString("data->module", file_get_contents(app_path('Livewire/Notifications/GeneralBell.php')));
    }
}
