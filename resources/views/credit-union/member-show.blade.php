<x-erp-layout module="credit_union" title="Member {{ $member->member_number }}">
    <div class="content">
        <livewire:credit-union.member-detail :member="$member" />
    </div>
</x-erp-layout>
