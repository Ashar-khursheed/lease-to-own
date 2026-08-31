<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function index(Request $request)
    {
        $applications = $request->user()->applications()->with('leaseAgreement.equipmentUnit')->latest()->get();

        return response()->json(['data' => $applications]);
    }

    public function store(Request $request)
    {
        // Customers don't self-submit applications yet — every application in
        // the current flow is created by an admin on the customer's behalf
        // via the New Application wizard (Admin\ApplicationController@store).
        abort(403, 'Applications are created by an Outdoor Fix representative.');
    }

    public function show(Request $request, Application $application)
    {
        abort_unless($application->customer_id === $request->user()->id, 404);

        return response()->json([
            'data' => $application->load(['leaseAgreement.equipmentUnit', 'leaseAgreement.payments']),
        ]);
    }
}
