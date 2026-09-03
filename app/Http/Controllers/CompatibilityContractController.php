<?php

namespace App\Http\Controllers;

use App\Domain\Events\Http\Controllers\EventTicketController;
use App\Domain\Organizations\Http\Controllers\OrganizationController;
use App\Domain\Platform\Http\Controllers\ApplicationConfigController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Response-shape adapter used only while older clients migrate to the V1
 * capability vocabulary. It delegates all business work to shared domain
 * controllers and only translates legacy field names/payload defaults.
 */
final class CompatibilityContractController extends Controller
{
    public function config(ApplicationConfigController $controller): JsonResponse
    {
        $response = $controller->show();
        $data = $response->getData(true);
        $application = (array) ($data['application'] ?? []);
        $data['app'] = $application['slug'] ?? null;
        $data['app_id'] = $application['id'] ?? null;

        return response()->json($data, $response->getStatusCode());
    }

    public function publicOrganizations(Request $request, OrganizationController $controller): JsonResponse
    {
        return $this->renameResponseKey($controller->publicIndex($request), 'organizations', 'productions');
    }

    public function publicOrganization(Request $request, string $slug, OrganizationController $controller): JsonResponse
    {
        return $this->renameResponseKey($controller->publicShow($request, $slug), 'organization', 'production');
    }

    public function myOrganizations(Request $request, OrganizationController $controller): JsonResponse
    {
        return $this->renameResponseKey($controller->mine($request), 'organizations', 'productions');
    }

    public function showOrganization(Request $request, int $id, OrganizationController $controller): JsonResponse
    {
        return $this->renameResponseKey($controller->show($request, $id), 'organization', 'production');
    }

    public function storeOrganization(Request $request, OrganizationController $controller): JsonResponse
    {
        return $this->renameResponseKey($controller->store($request), 'organization', 'production');
    }

    public function updateOrganization(Request $request, int $id, OrganizationController $controller): JsonResponse
    {
        return $this->renameResponseKey($controller->update($request, $id), 'organization', 'production');
    }

    public function storeCourtesy(Request $request, EventTicketController $controller): JsonResponse
    {
        $request->merge([
            'price' => 0,
            'ticket_type' => 'courtesy',
        ]);

        return $controller->store($request);
    }

    private function renameResponseKey(JsonResponse $response, string $from, string $to): JsonResponse
    {
        $data = $response->getData(true);
        if (array_key_exists($from, $data)) {
            $data[$to] = $data[$from];
            unset($data[$from]);
        }

        return response()->json($data, $response->getStatusCode());
    }
}
