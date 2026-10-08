<?php

namespace App\Livewire\HealthSafety\Concerns;

use App\Services\HealthSafety\EquipmentExpiryService;
use App\Services\HealthSafety\EquipmentLabelService;
use App\Services\HealthSafety\LabelBatch;
use App\Services\HealthSafety\QrLinks;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Ticking rows and asking for a label sheet on the extinguisher and kit lists (manage_equipment only). The component only
 * collects ids: it hands them to the download route through a one-use LabelBatch, and the download (controller and
 * EquipmentLabelService) checks permission and scope again.
 */
trait PrintsEquipmentLabels
{
    /** '' or 'none' (items with no label printed yet) */
    #[Url(except: '')]
    public string $label = '';

    /** @var list<int|string> ids ticked on the page */
    public array $selected = [];

    public string $labelLayout = EquipmentLabelService::LAYOUT_STANDARD;

    /** 'extinguisher' or 'kit' */
    abstract protected function labelType(): string;

    /** The list's current filters, scoped to the user, as a query. */
    abstract protected function labelQuery(EquipmentExpiryService $equipment): Builder;

    protected function normaliseLabelFilter(): void
    {
        if (! in_array($this->label, ['', 'none'], true)) {
            $this->label = '';
        }
    }

    public function printSelected(LabelBatch $batches): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_equipment');

        $this->sendToPrint($batches, array_map('intval', $this->selected));
    }

    public function printAllInFilter(LabelBatch $batches, EquipmentExpiryService $equipment, EquipmentLabelService $labels): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_equipment');

        $this->sendToPrint($batches, $labels->idsFrom($this->labelQuery($equipment)));
    }

    /** @param  list<int>  $ids */
    protected function sendToPrint(LabelBatch $batches, array $ids): void
    {
        $this->resetErrorBag('labels');

        if ($ids === []) {
            $this->addError('labels', 'Tick at least one row, or print everything in the filter.');

            return;
        }

        if (count($ids) > EquipmentLabelService::max()) {
            $this->addError('labels', 'A sheet holds at most '.EquipmentLabelService::max().' labels. Narrow the filter (for example to one site, or "no label yet") or tick fewer rows.');

            return;
        }

        $layout = array_key_exists($this->labelLayout, EquipmentLabelService::LAYOUTS) ? $this->labelLayout : EquipmentLabelService::LAYOUT_STANDARD;
        $token = $batches->stash($this->actor(), $ids, $layout);

        $this->selected = [];
        $this->redirect(route($this->labelType() === 'kit' ? 'health_safety.labels.kits' : 'health_safety.labels.extinguishers', ['batch' => $token]));
    }

    /** @return array<string, mixed> what the list view needs to draw the label controls */
    protected function labelViewData(): array
    {
        return [
            'labelBase' => app(QrLinks::class)->baseUrl(),
            'labelLayouts' => EquipmentLabelService::LAYOUTS,
            'labelMax' => EquipmentLabelService::max(),
        ];
    }
}
