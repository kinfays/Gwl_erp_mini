<?php

namespace App\Http\Controllers\Transport;

use App\Exports\Transport\VehicleExpensesExport;
use App\Http\Controllers\Controller;
use App\Services\Transport\TransportScope;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class TransportExpenseExportController extends Controller
{
    public function __invoke(Request $request, TransportScope $scope)
    {
        $user = $request->user();

        $request->validate([
            // No `exists:vehicles` rule: a vehicle that does not exist and one outside the user's reach must look alike (404).
            'vehicle_id' => ['nullable', 'integer'],
            'expense_type' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        if ($request->filled('vehicle_id')) {
            $scope->findVehicle($user, $request->integer('vehicle_id'));
        }

        return Excel::download(
            new VehicleExpensesExport($user, $request->only(['vehicle_id', 'expense_type', 'date_from', 'date_to'])),
            'transport-expenses-'.now()->format('Ymd-His').'.xlsx'
        );
    }
}
