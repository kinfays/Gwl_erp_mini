<?php

namespace App\Http\Controllers\Transport;

use App\Exports\Transport\VehicleExpensesExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class TransportExpenseExportController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'expense_type' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return Excel::download(
            new VehicleExpensesExport($request->only(['vehicle_id', 'expense_type', 'date_from', 'date_to'])),
            'transport-expenses-'.now()->format('Ymd-His').'.xlsx'
        );
    }
}
