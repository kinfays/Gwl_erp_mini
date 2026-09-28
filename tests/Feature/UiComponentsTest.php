<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * The shared <x-ui.*> components render, keep the visible text they are given, and map stored
 * status values to the documented tones.
 */
class UiComponentsTest extends TestCase
{
    protected function render(string $template, array $data = []): string
    {
        return Blade::render($template, $data + ['errors' => new ViewErrorBag()]);
    }

    public function test_status_pill_maps_each_domain_to_its_tone_and_keeps_the_stored_label(): void
    {
        $cases = [
            ['leave', 'Pending Approval', 'ui-pill-warning', 'Pending Approval'],
            ['leave', 'Approved', 'ui-pill-success', 'Approved'],
            ['leave', 'Denied', 'ui-pill-danger', 'Denied'],
            ['leave', 'Planned', 'ui-pill-muted', 'Planned'],
            ['recommendation', 'Recommended', 'ui-pill-info', 'Recommended'],
            ['letter', 'Dispatched', 'ui-pill-lagoon', 'Dispatched'],
            ['checkout', 'auto', 'ui-pill-warning', 'Auto checkout'],
            ['checkout', 'self', 'ui-pill-success', 'Self checkout'],
            ['issue', 'in_review', 'ui-pill-info', 'In Review'],
            ['severity', 'critical', 'ui-pill-danger', 'Critical'],
        ];

        foreach ($cases as [$domain, $status, $toneClass, $label]) {
            $html = $this->render('<x-ui.status-pill :domain="$domain" :status="$status" />', compact('domain', 'status'));

            $this->assertStringContainsString($toneClass, $html, "{$domain}:{$status}");
            $this->assertStringContainsString('>'.$label.'</span>', $html, "{$domain}:{$status}");
        }

        $unknown = $this->render('<x-ui.status-pill status="Something Else" />');
        $this->assertStringContainsString('ui-pill-muted', $unknown);
        $this->assertStringContainsString('Something Else', $unknown);
    }

    public function test_stat_tile_only_shows_a_delta_or_sparkline_when_given_one(): void
    {
        $plain = $this->render('<x-ui.stat-tile label="Staff in zone" value="73" icon="users" />');
        $this->assertStringContainsString('Staff in zone', $plain);
        $this->assertStringContainsString('ui-chip-primary', $plain);
        $this->assertStringNotContainsString('ui-delta', $plain);
        $this->assertStringNotContainsString('ui-stat-spark', $plain);

        $rich = $this->render(
            '<x-ui.stat-tile label="Pending" value="12" delta="−4 vs last week" delta-tone="good" delta-direction="down" :sparkline="[3, 5, 4, 9]" />'
        );
        $this->assertStringContainsString('ui-delta-good', $rich);
        $this->assertStringContainsString('ui-stat-spark', $rich);
    }

    public function test_table_renders_head_rows_empty_state_and_footer(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-ui.table label="Employees" :empty="true" empty-title="No employees match these filters">
                <x-slot:head><tr><th>Name</th></tr></x-slot:head>
                <x-slot:footer>Pager</x-slot:footer>
            </x-ui.table>
            BLADE);

        $this->assertStringContainsString('role="region"', $html);
        $this->assertStringContainsString('<th>Name</th>', $html);
        $this->assertStringContainsString('No employees match these filters', $html);
        $this->assertStringContainsString('Pager', $html);
    }

    public function test_form_controls_pass_bindings_through_and_link_the_label(): void
    {
        $html = $this->render('<x-ui.input label="Display Name" wire:model.defer="editRoleDisplayName" />');

        $this->assertStringContainsString('wire:model.defer="editRoleDisplayName"', $html);
        $this->assertStringContainsString('for="f-editroledisplayname"', $html);
        $this->assertStringContainsString('id="f-editroledisplayname"', $html);

        $segmented = $this->render(
            '<x-ui.segmented label="Range" wire:model.live="range" :options="[\'7d\' => \'7 days\', \'30d\' => \'30 days\']" />'
        );
        $this->assertSame(2, substr_count($segmented, 'wire:model.live="range"'));
        $this->assertStringContainsString('<legend class="sr-only-text">Range</legend>', $segmented);
    }

    public function test_buttons_render_as_links_or_buttons_with_their_variant(): void
    {
        $this->assertStringContainsString('<a href="/x"', $this->render('<x-ui.button href="/x" variant="primary">Go</x-ui.button>'));

        $button = $this->render('<x-ui.button variant="danger-solid" wire:click="deleteRole" loading="deleteRole">Delete Role</x-ui.button>');
        $this->assertStringContainsString('btn-danger-solid', $button);
        $this->assertStringContainsString('wire:click="deleteRole"', $button);
        $this->assertStringContainsString('wire:target="deleteRole"', $button);
    }
}
