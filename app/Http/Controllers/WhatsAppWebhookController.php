<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppWebhookService;
use Illuminate\Http\Request;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppWebhookService $service)
    {
        $mode = (string) ($request->query('hub.mode') ?? $request->query('hub_mode') ?? '');
        $token = (string) ($request->query('hub.verify_token') ?? $request->query('hub_verify_token') ?? '');
        $challenge = (string) ($request->query('hub.challenge') ?? $request->query('hub_challenge') ?? '');

        $verifiedChallenge = $service->verify($mode, $token, $challenge);

        if ($verifiedChallenge === null) {
            return response('Invalid webhook verification token.', 403);
        }

        return response($verifiedChallenge, 200)
            ->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppWebhookService $service)
    {
        $rawBody = (string) $request->getContent();

        if (! $service->signatureIsValid($rawBody, $request->header('X-Hub-Signature-256'))) {
            return response()->json(['message' => 'Invalid webhook signature.'], 403);
        }

        $service->process((array) $request->json()->all());

        return response()->json(['received' => true]);
    }
}
