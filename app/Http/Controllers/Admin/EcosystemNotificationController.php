<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller; use App\Services\Admin\EcosystemNotificationService; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class EcosystemNotificationController extends Controller { public function __construct(private readonly EcosystemNotificationService $service){} public function index(Request $request):JsonResponse{return $this->service->index($request);} public function preview(Request $request):JsonResponse{return $this->service->preview($request);} public function store(Request $request):JsonResponse{return $this->service->store($request);} }
