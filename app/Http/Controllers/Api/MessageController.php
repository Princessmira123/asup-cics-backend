<?php
// app/Http/Controllers/Api/MessageController.php
//
// Backs the Messages screen on the member side. Starts empty for every
// member — no seeded conversations.
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function inbox(Request $request)
    {
        $messages = Message::where('member_id', $request->user()->id)
            ->where('sender_type', 'admin')
            ->latest()
            ->get();
        return response()->json(['success' => true, 'messages' => $messages]);
    }

    public function sent(Request $request)
    {
        $messages = Message::where('member_id', $request->user()->id)
            ->where('sender_type', 'member')
            ->latest()
            ->get();
        return response()->json(['success' => true, 'messages' => $messages]);
    }

    public function send(Request $request)
    {
        $request->validate([
            'subject' => 'nullable|string|max:150',
            'body'    => 'required|string|max:2000',
        ]);

        $message = Message::create([
            'member_id'   => $request->user()->id,
            'sender_type' => 'member',
            'subject'     => $request->subject,
            'body'        => $request->body,
            'is_read'     => false,
        ]);

        return response()->json(['success' => true, 'message' => 'Message sent to admin', 'data' => $message]);
    }

    public function markRead(Request $request, $id)
    {
        Message::where('id', $id)
            ->where('member_id', $request->user()->id)
            ->where('sender_type', 'admin')
            ->update(['is_read' => true]);
        return response()->json(['success' => true]);
    }
}
