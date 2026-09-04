<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventManagementService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class EventManagementController extends Controller
{
    public function __construct(private readonly EventManagementService $service) {}

    public function mine(Request $request){return $this->service->mine($request);}
    public function show(Request $request,int $id){return $this->service->show($request,$id);}
    public function store(Request $request){return $this->service->store($request);}
    public function update(Request $request,int $id){return $this->service->update($request,$id);}
    public function publish(Request $request,int $id){return $this->service->publish($request,$id);}
    public function unpublish(Request $request,int $id){return $this->service->unpublish($request,$id);}
    public function destroy(Request $request,int $id){return $this->service->destroy($request,$id);}
}
