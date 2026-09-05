<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller; use App\Services\Admin\InteractionMaintenanceService; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class InteractionMaintenanceController extends Controller { public function __construct(private readonly InteractionMaintenanceService $service){} public function destroySelected(Request $request):JsonResponse{return $this->service->destroySelected($request);} public function destroyAll(Request $request):JsonResponse{return $this->service->destroyAll($request);} }
