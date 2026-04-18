<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $pageTitle }} - ERP Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 font-sans">
    <div class="flex min-h-screen">
        <nav class="w-64 bg-slate-900 text-slate-300 p-6">
            <h2 class="text-white text-xl font-bold mb-8 italic">ERP System</h2>
            <div class="mb-4 text-xs font-semibold uppercase tracking-wider text-slate-500">UAC Modules</div>
            <ul class="space-y-2">
                <li><a href="{{ route('uac.users') }}" class="block p-2 hover:bg-slate-800 rounded">Staff Users</a></li>
                <li><a href="{{ route('uac.roles') }}" class="block p-2 hover:bg-slate-800 rounded">Roles & Rights</a></li>
                <li><a href="{{ route('uac.import') }}" class="block p-2 hover:bg-slate-800 rounded">Bulk HR Import</a></li>
            </ul>
            
            <div class="mt-8 mb-4 text-xs font-semibold uppercase tracking-wider text-slate-500">App Modules</div>
            <ul class="space-y-2 text-sm">
                <li><a href="#" class="block p-2 opacity-50 cursor-not-allowed">Leave Management</a></li>
                <li><a href="#" class="block p-2 opacity-50 cursor-not-allowed">Letter Management</a></li>
                <li><a href="#" class="block p-2 opacity-50 cursor-not-allowed">Visitors Log</a></li>
            </ul>
        </nav>

        <main class="flex-1 p-8">
            <div class="flex justify-between items-center border-b pb-4 mb-6">
                <h1 class="text-2xl font-bold text-slate-800">{{ $pageTitle }}</h1>
                <div class="flex items-center space-x-4">
                    <span class="bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded">{{ $currentRoleName }}</span> [cite: 20]
                    <span class="text-slate-600 font-medium">{{ $currentUser->full_name }}</span>
                </div>
            </div>
            @yield('content')
        </main>
    </div>
</body>
</html>