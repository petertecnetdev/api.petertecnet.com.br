<?php

namespace App\Domain\PropertyManagement\Http\Controllers;

use App\Domain\PropertyManagement\Services\LeaseAgreementService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaseAgreementController extends Controller
{
    public function __construct(private readonly LeaseAgreementService $agreements) {}

    public function store(Request $request): JsonResponse
    {
        $application = strtolower((string) $request->route('application'));
        $data = $request->validate([
            'property_id' => ['required', 'integer'],
            'tenant_name' => ['required', 'string', 'max:180'],
            'tenant_email' => ['required', 'email', 'max:180'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'rent_amount' => ['required', 'numeric', 'min:0.01'],
            'due_day' => ['required', 'integer', 'between:1,28'],
            'guarantee_type' => ['required', Rule::in(['none', 'cash_deposit', 'guarantor', 'insurance', 'investment_fiduciary'])],
            'security_rent_multiplier' => ['nullable', 'integer', 'between:0,3'],
            'included_charges' => ['nullable', 'array'],
            'included_charges.*' => [Rule::in(['water', 'electricity', 'iptu', 'internet', 'condominium'])],
            'clauses' => ['nullable', 'array'],
            'clauses.*' => ['string', 'max:3000'],
            'terms' => ['nullable', 'array'],
        ]);

        $property = DB::table('managed_properties')
            ->where('id', $data['property_id'])
            ->where('application_slug', $application)
            ->where('owner_user_id', $request->user()->id)
            ->first();
        abort_unless($property, 422, 'Imóvel inválido ou não pertence a esta conta.');

        $data = $this->agreements->validateGuarantee($data);
        $tenantEmail = mb_strtolower($data['tenant_email']);
        $tenant = User::query()->whereRaw('LOWER(email) = ?', [$tenantEmail])->first();
        $included = $data['included_charges'] ?? [];
        $clauses = $data['clauses'] ?? [];
        $terms = $data['terms'] ?? [];

        $id = DB::transaction(function () use ($request, $application, $data, $tenant, $tenantEmail, $included, $clauses, $terms) {
            $id = DB::table('lease_agreements')->insertGetId([
                'application_slug' => $application,
                'property_id' => $data['property_id'],
                'landlord_user_id' => $request->user()->id,
                'tenant_user_id' => $tenant?->id,
                'tenant_name' => $data['tenant_name'],
                'tenant_email' => $tenantEmail,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'rent_amount' => $data['rent_amount'],
                'due_day' => $data['due_day'],
                'guarantee_type' => $data['guarantee_type'],
                'security_rent_multiplier' => $data['security_rent_multiplier'],
                'security_amount' => $data['security_amount'],
                'included_charges' => json_encode($included),
                'clauses' => json_encode($clauses),
                'terms' => json_encode($terms),
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->agreements->syncDefaultObligations($id, $included);
            return $id;
        });

        return response()->json(['data' => DB::table('lease_agreements')->find($id)], 201);
    }
}
