<x-erp-layout module="credit_union" title="Loan {{ $loan->loan_number }}">
    <div class="content">
        <livewire:credit-union.loan-show :loan="$loan" />
    </div>
</x-erp-layout>
