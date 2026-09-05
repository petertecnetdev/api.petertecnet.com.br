<?php
namespace App\Http\Controllers;
use App\Services\ApplicationService; use Illuminate\Http\JsonResponse;
class ApplicationController extends Controller { public function __construct(private readonly ApplicationService $service){} public function index():JsonResponse{return $this->service->index();} public function show(string $slug):JsonResponse{return $this->service->show($slug);} }
