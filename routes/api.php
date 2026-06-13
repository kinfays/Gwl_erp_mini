<?php

use App\Http\Controllers\Api\AgentReportController;
use App\Http\Controllers\Api\TransportApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:120,1')->group(function () {
    Route::post('/agent/report', [AgentReportController::class, 'store'])->name('api.agent.report');

    Route::get('/vehicles/my-vehicle', [TransportApiController::class, 'myVehicle'])->name('api.transport.my-vehicle');
    Route::post('/mileage-logs', [TransportApiController::class, 'storeMileage'])->name('api.transport.mileage-logs.store');
    Route::post('/vehicle-issues', [TransportApiController::class, 'storeIssue'])->name('api.transport.vehicle-issues.store');
});
