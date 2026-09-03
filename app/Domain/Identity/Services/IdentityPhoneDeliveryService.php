<?php

namespace App\Domain\Identity\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class IdentityPhoneDeliveryService
{
    public function available(): bool
    {
        return (string) config('identity.phone.provider', '') === 'twilio'
            && trim((string) config('identity.phone.twilio.sid', '')) !== ''
            && trim((string) config('identity.phone.twilio.token', '')) !== ''
            && trim((string) config('identity.phone.twilio.from', '')) !== '';
    }

    public function send(string $phone, string $code): void
    {
        if (! $this->available()) {
            throw new RuntimeException('PHONE_DELIVERY_NOT_CONFIGURED');
        }

        $sid = (string) config('identity.phone.twilio.sid');
        $token = (string) config('identity.phone.twilio.token');
        $from = (string) config('identity.phone.twilio.from');
        $to = str_starts_with($phone, '+') ? $phone : '+'.$phone;

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->timeout(8)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => $from,
                'To' => $to,
                'Body' => "Peter Tecnet: seu código de verificação é {$code}. Ele expira em poucos minutos.",
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('PHONE_DELIVERY_FAILED');
        }
    }
}
