<?php
// app/Http/Controllers/Api/HouseholdController.php
//
// Backs the Household Operation screen. Starts empty for every member —
// requests only appear once a member actually submits one.
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HouseholdRequest;
use Illuminate\Http\Request;

class HouseholdController extends Controller
{
    public function index(Request $request)
    {
        $requests = HouseholdRequest::where('member_id', $request->user()->id)->latest()->get();
        return response()->json(['success' => true, 'requests' => $requests]);
    }

    public function request(Request $request)
    {
        $request->validate([
            'operation_type'    => 'required|string|max:100',
            'amount_requested'  => 'required|numeric|min:0',
            'description'       => 'nullable|string|max:1000',
        ]);

        $hr = HouseholdRequest::create([
            'member_id'         => $request->user()->id,
            'operation_type'    => $request->operation_type,
            'amount_requested'  => $request->amount_requested,
            'description'       => $request->description,
            'status'            => 'pending',
        ]);

        return response()->json(['success' => true, 'message' => 'Household request submitted', 'data' => $hr]);
    }
}
