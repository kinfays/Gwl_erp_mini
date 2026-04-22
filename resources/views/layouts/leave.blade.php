<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Management</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-100">

<div class="app">
    {{-- Top Bar --}}
    <div class="topbar">
        <div class="topbar-left">
            <span class="tb-title">GWCL Portal</span>
            <span class="tb-sep">/</span>
            <span class="tb-page">Leave Management</span>
        </div>

        <div class="topbar-right">
            <span>{{ auth()->user()->employee->full_name }}</span>
        </div>
    </div>

    <div class="layout">
        {{-- Sidebar --}}
        <aside class="sidebar">
            <div class="sb-module">
                <div class="sb-mod-name">Leave Management</div>
                <div class="sb-mod-sub">
                    {{ auth()->user()->employee->role_name }}
                </div>
            </div>

            <nav class="sb-nav">
                <a href="{{ route('leave.dashboard') }}" class="sb-item">HR Dashboard</a>
                <a href="{{ route('leave.requests') }}" class="sb-item">All Requests</a>
                <a href="{{ route('leave.apply') }}" class="sb-item">Apply for Leave</a>
                <a href="{{ route('leave.my-history') }}" class="sb-item">My Leave History</a>
                <a href="{{ route('leave.team') }}" class="sb-item">Team Leave</a>

                @can('leave.manage_compulsory')
                    <a href="{{ route('leave.compulsory') }}" class="sb-item">
                        Compulsory Leave
                    </a>
                @endcan
            </nav>
        </aside>

        {{-- Main Content --}}
        <main class="main">
            {{ $slot }}
        </main>
    </div>
</div>

@livewireScripts
</body>
</html>
