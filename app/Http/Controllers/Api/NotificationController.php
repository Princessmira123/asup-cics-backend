<?php
// app/Http/Controllers/Api/NotificationController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifs = \App\Models\Notification::where('member_id', $request->user()->id)->latest()->paginate(20);
        return response()->json(['success' => true, 'notifications' => $notifs]);
    }

    public function markRead(Request $request, $id)
    {
        \App\Models\Notification::where('id', $id)->where('member_id', $request->user()->id)->update(['is_read' => true]);
        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request)
    {
        \App\Models\Notification::where('member_id', $request->user()->id)->update(['is_read' => true]);
        return response()->json(['success' => true]);
    }

    public function updateFcmToken(Request $request)
    {
        $request->validate(['fcm_token' => 'required|string']);
        $request->user()->update(['fcm_token' => $request->fcm_token]);
        return response()->json(['success' => true]);
    }
}
