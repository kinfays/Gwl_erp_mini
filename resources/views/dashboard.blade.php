<x-app-layout>
    <div class="min-h-screen bg-gray-100">

        {{-- Top Navigation --}}
        <div class="bg-white shadow px-6 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <img src="/images/gwl.png" alt="GWL Logo" class="h-8">
                <span class="text-lg font-semibold">GWL Mini Erp Portal</span>
            </div>

            <div class="flex items-center space-x-6">
                {{-- Notification Bell (placeholder) --}}
                <button class="relative">
                    🔔
                    <span class="absolute top-0 right-0 bg-red-500 text-white text-xs rounded-full px-1">
                        0
                    </span>
                </button>

                {{-- User Info --}}
                <div class="flex items-center space-x-3">
                    <div class="h-8 w-8 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold">
                        {{ strtoupper(substr(auth()->user()->employee->full_name ?? 'U', 0, 1)) }}
                    </div>
                    <div>
                        <div class="text-sm font-medium">
                            {{ auth()->user()->employee->full_name ?? 'User' }}
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="text-xs text-red-600">Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- Greeting Section --}}
        <div class="px-8 py-6">
            <h1 class="text-2xl font-semibold">
                {{ dashboardGreeting() }},
                {{ explode(' ', auth()->user()->employee->full_name ?? 'User')[0] }}
            </h1>

            <p class="text-gray-600 mt-1">
                {{ now()->format('l, jS F Y') }}
                —
                {{ auth()->user()->employee->district->district_name ?? '' }},
                {{ auth()->user()->employee->region->region_name ?? '' }}
                —
                {{ auth()->user()->roles->pluck('display_name')->join(', ') }}
            </p>
        </div>

        {{-- Module Cards --}}
        <div class="px-8 pb-10">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                @foreach (dashboardModules() as $module)
                    <a href="{{ $module['route'] }}"
                       class="bg-white rounded shadow p-5 border-t-4
                       {{ $module['slug'] === 'leave' ? 'border-blue-500' : 'border-gray-200' }}">
                        
                        <div class="flex justify-between items-start">
                            <div class="text-3xl">{{ $module['icon'] }}</div>

                            @if ($module['slug'] === 'leave')
                                <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">
                                    everyone
                                </span>
                            @endif
                        </div>

                        <h3 class="mt-4 text-lg font-semibold">
                            {{ $module['title'] }}
                        </h3>

                        <p class="text-gray-600 text-sm mt-1">
                            {{ $module['description'] }}
                        </p>
                    </a>
                @endforeach

            </div>
        </div>
    </div>
</x-app-layout>