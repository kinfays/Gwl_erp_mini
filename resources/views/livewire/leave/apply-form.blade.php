<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="lg:col-span-2 bg-white rounded-xl border p-6">
    <h2 class="text-lg font-semibold mb-4">Apply for Leave</h2>

    @if ($errors->any())
      <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded">
        <ul class="text-sm text-red-700 list-disc list-inside">
          @foreach($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
      </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="text-sm font-medium">Leave Type</label>
        <select wire:model="leave_type" class="w-full border rounded p-2">
          <option>Annual</option>
          <option>Casual</option>
          <option>Paternity</option>
          <option>Maternity</option>
          <option>Sick</option>
        </select>
      </div>

      <div class="flex items-end">
        <div class="text-sm">
          <div class="text-slate-500">Working days</div>
          <div class="text-2xl font-bold">{{ $working_days }}</div>
        </div>
      </div>

      <div>
        <label class="text-sm font-medium">Start Date</label>
        <input type="date" wire:model="start_date" class="w-full border rounded p-2">
      </div>

      <div>
        <label class="text-sm font-medium">End Date</label>
        <input type="date" wire:model="end_date" class="w-full border rounded p-2">
      </div>

      <div class="md:col-span-2">
        <label class="text-sm font-medium">Reason</label>
        <textarea wire:model="leave_details" class="w-full border rounded p-2" rows="4"></textarea>
      </div>

      <div class="md:col-span-2">
        <label class="text-sm font-medium">Attachment</label>
        <input type="file" wire:model="file_attachment" class="w-full">
      </div>
    </div>

    <div class="mt-5 flex gap-3">
      <button wire:click="savePlanned" class="px-4 py-2 rounded bg-slate-200">Save as Planned</button>
      <button wire:click="submit" class="px-4 py-2 rounded bg-blue-600 text-white">Submit Request</button>
    </div>
  </div>

  <div class="bg-white rounded-xl border p-6">
    <h3 class="font-semibold mb-3">Leave Balance (This Year)</h3>
    <div class="space-y-2 text-sm">
      @foreach($balances as $type => $remain)
        <div class="flex justify-between">
          <span>{{ $type }}</span>
          <span class="font-semibold">{{ $remain }}</span>
        </div>
      @endforeach
    </div>
  </div>
</div>