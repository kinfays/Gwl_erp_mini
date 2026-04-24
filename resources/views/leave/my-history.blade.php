<x-app-layout>
  <a href="{{ route('leave.export.approved.excel') }}" class="btn">
    Export Excel
</a>

  <div class="p-6">
    <livewire:leave.my-history />
  </div>
</x-app-layout>