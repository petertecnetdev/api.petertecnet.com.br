<?php

namespace App\Http\Controllers;

use App\Services\EventPassService;
use Illuminate\Http\Request;

class EventPassController extends Controller
{
    public function __construct(private readonly EventPassService $passes)
    {
    }

    public function claim(Request $request, int $ticketId)
    {
        $result = $this->passes->claim($ticketId, $request->user());
        $status = $result['status'];
        unset($result['status']);

        return response()->json($result, $status);
    }

    public function mine(Request $request)
    {
        return response()->json($this->passes->mine($request->user()));
    }

    public function show(Request $request, int $passId)
    {
        return response()->json($this->passes->show($request->user(), $passId));
    }

    public function participants(Request $request, int $eventId)
    {
        return response()->json($this->passes->participants($request->user(), $eventId));
    }

    public function validateToken(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:180',
            'event_id' => 'required|integer|exists:events,id',
        ]);

        $result = $this->passes->validateToken(
            $request->user(),
            (string) $data['token'],
            (int) $data['event_id']
        );
        $status = $result['status'];

        return response()->json([
            'message' => $result['message'],
            'pass' => $result['pass'],
        ], $status);
    }

    public function eventStats(Request $request, int $eventId)
    {
        return response()->json($this->passes->eventStats($request->user(), $eventId));
    }
}
