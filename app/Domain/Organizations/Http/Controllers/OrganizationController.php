<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Domain\Organizations\Services\OrganizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationService $service) {}

    public function publicIndex(Request $request){return $this->service->publicIndex($request);}
    public function publicShow(Request $request,string $slug){return $this->service->publicShow($request,$slug);}
    public function mine(Request $request){return $this->service->mine($request);}
    public function show(Request $request,int $id){return $this->service->show($request,$id);}
    public function store(Request $request){return $this->service->store($request);}
    public function update(Request $request,int $id){return $this->service->update($request,$id);}
    public function destroy(Request $request,int $id){return $this->service->destroy($request,$id);}
}
