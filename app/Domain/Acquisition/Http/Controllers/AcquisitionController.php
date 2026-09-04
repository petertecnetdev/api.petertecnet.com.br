<?php

namespace App\Domain\Acquisition\Http\Controllers;

use App\Domain\Acquisition\Services\AcquisitionAgentService;
use App\Domain\Acquisition\Services\AcquisitionOnboardingService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class AcquisitionController extends Controller
{
    public function __construct(
        private readonly AcquisitionAgentService $agents,
        private readonly AcquisitionOnboardingService $onboarding,
    ) {}

    public function context(Request $request)
    {
        return response()->json($this->agents->context($request->user()));
    }

    public function dashboard(Request $request)
    {
        return response()->json($this->agents->dashboard($request->user()));
    }

    public function referrals(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'accepted', 'expired', 'revoked'])],
            'q' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $referrals = $this->agents->referrals($request->user(), $data);
        $referrals->appends($request->query());

        return response()->json(['referrals' => $referrals]);
    }

    public function onboard(Request $request)
    {
        $request->replace($this->onboarding->normalize($request->all()));
        $data = $request->validate([
            'user.first_name' => ['required','string','min:2','max:100'],
            'user.last_name' => ['nullable','string','max:100'],
            'user.email' => ['required','email','max:255'],
            'production.name' => ['required','string','min:2','max:255'],
            'production.fantasy' => ['nullable','string','max:255'],
            'production.cnpj' => ['nullable','regex:/^\d{14}$/'],
            'production.phone' => ['nullable','string','max:30'],
            'production.description' => ['nullable','string','max:10000'],
            'production.city' => ['nullable','string','max:120'],
            'production.uf' => ['nullable','string','size:2'],
            'production.address' => ['nullable','string','max:255'],
            'production.website_url' => ['nullable','url:http,https','max:2048'],
            'production.instagram_url' => ['nullable','url:http,https','max:2048'],
            'events' => ['required','array','min:1','max:20'],
            'events.*.title' => ['required','string','min:2','max:255'],
            'events.*.description' => ['required','string','max:50000'],
            'events.*.start_date' => ['required','date','after:now'],
            'events.*.end_date' => ['required','date'],
            'events.*.event_format' => ['nullable', Rule::in(['in_person','online','hybrid'])],
            'events.*.address' => ['nullable','string','max:500'],
            'events.*.venue' => ['nullable','string','max:255'],
            'events.*.city' => ['nullable','string','max:120'],
            'events.*.uf' => ['nullable','string','size:2'],
            'events.*.online_url' => ['nullable','url:http,https','max:2048'],
            'events.*.commission_percentage' => ['required','numeric','min:0','max:100'],
            'events.*.tickets' => ['required','array','min:1','max:50'],
            'events.*.tickets.*.name' => ['required','string','max:255'],
            'events.*.tickets.*.quantity' => ['required','integer','min:1','max:100000'],
            'events.*.tickets.*.price' => ['required','numeric','min:0','max:999999.99'],
            'events.*.tickets.*.ticket_type' => ['nullable', Rule::in(['courtesy','standard','vip','premium','student','half','full'])],
            'events.*.tickets.*.limit_date' => ['nullable','date'],
            'events.*.tickets.*.description' => ['nullable','string','max:5000'],
        ]);

        $this->validateEventInvariants($data);

        return response()->json(
            $this->onboarding->onboard($request->user(), $data),
            201,
        );
    }

    private function validateEventInvariants(array $payload): void
    {
        $errors = [];
        $productionAddress = trim((string) data_get($payload, 'production.address', ''));

        foreach (($payload['events'] ?? []) as $index => $event) {
            $start = CarbonImmutable::parse((string) $event['start_date']);
            $end = CarbonImmutable::parse((string) $event['end_date']);
            $format = (string) ($event['event_format'] ?? 'in_person');

            if ($end->lessThanOrEqualTo($start)) {
                $errors["events.{$index}.end_date"][] = 'A data de término deve ser posterior à data de início.';
            }

            if (in_array($format, ['in_person', 'hybrid'], true)) {
                $eventAddress = trim((string) ($event['address'] ?? ''));
                if ($eventAddress === '' && $productionAddress === '') {
                    $errors["events.{$index}.address"][] = 'Informe o endereço do evento ou da produção para eventos presenciais ou híbridos.';
                }
            }

            if (in_array($format, ['online', 'hybrid'], true) && blank($event['online_url'] ?? null)) {
                $errors["events.{$index}.online_url"][] = 'Informe o link online para eventos online ou híbridos.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function resend(Request $request, int $referralId)
    {
        $result = $this->onboarding->resend($request->user(), $referralId);

        return response()->json($result, $result['email_sent'] ? 200 : 503);
    }

    public function updateCommission(Request $request, int $eventId)
    {
        $data = $request->validate([
            'percentage' => 'required|numeric|min:0|max:100',
        ]);

        $commission = $this->agents->updateCommission(
            $request->user(),
            $eventId,
            (float) $data['percentage'],
        );

        return response()->json([
            'message' => 'Comissão do evento atualizada.',
            'commission' => $commission,
        ]);
    }

    public function publicReferral(string $token)
    {
        return response()->json($this->onboarding->publicReferral($token));
    }

    public function activate(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|min:40|max:128',
            'activation_code' => 'required|string|min:6|max:16',
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => 'nullable|string|same:password',
        ]);

        return response()->json($this->onboarding->activate($data));
    }
}
