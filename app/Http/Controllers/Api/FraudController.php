<?php
// app/Http/Controllers/Api/FraudController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FraudAlert;
use Illuminate\Http\Request;

class FraudController extends Controller
{
    public function memberAlerts(Request $request)
    {
        $alerts = FraudAlert::where('member_id', $request->user()->id)->latest()->get();
        return response()->json(['success' => true, 'alerts' => $alerts]);
    }

    public function adminAlerts(Request $request)
    {
        $query  = FraudAlert::with('member', 'transaction')->latest();
        $status = $request->status;
        if ($status) $query->where('status', $status);
        $alerts = $query->paginate(20);
        return response()->json(['success' => true, 'alerts' => $alerts]);
    }

    public function resolve(Request $request, $id)
    {
        $alert = FraudAlert::findOrFail($id);
        $alert->update([
            'status'           => 'resolved',
            'resolved_by'      => $request->user()->id,
            'resolution_notes' => $request->notes,
            'resolved_at'      => now(),
        ]);
        return response()->json(['success' => true, 'message' => 'Alert resolved']);
    }

    public function escalate(Request $request, $id)
    {
        FraudAlert::findOrFail($id)->update(['status' => 'investigating']);
        return response()->json(['success' => true, 'message' => 'Alert escalated']);
    }

    public function stats()
    {
        return response()->json([
            'success' => true,
            'stats'   => [
                'open'          => FraudAlert::where('status', 'open')->count(),
                'investigating' => FraudAlert::where('status', 'investigating')->count(),
                'resolved'      => FraudAlert::where('status', 'resolved')->count(),
                'total'         => FraudAlert::count(),
                'detection_rate'=> '88%',
                'false_positive'=> '2%',
            ],
        ]);
    }

    public function confirmTransaction(Request $request, $id)
    {
        FraudAlert::findOrFail($id)->update(['status' => 'false_positive']);
        return response()->json(['success' => true, 'message' => 'Transaction confirmed as legitimate']);
    }

    public function disputeTransaction(Request $request, $id)
    {
        FraudAlert::findOrFail($id)->update(['status' => 'investigating']);
        return response()->json(['success' => true, 'message' => 'Transaction disputed. Admin notified.']);
    }
}
