<x-erp-layout module="credit_union" title="Interest Distribution {{ $distribution->period_label }}">
    <div class="content">
        <livewire:credit-union.interest-distribution-show :distribution="$distribution" />
    </div>
</x-erp-layout>
