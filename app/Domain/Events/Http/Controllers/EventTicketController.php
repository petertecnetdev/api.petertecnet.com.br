<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventTicketService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class EventTicketController extends Controller
{
    public function __construct(private readonly EventTicketService $service) {}

    public function index(Request $request,int $eventId){return $this->service->index($request,$eventId);}
    public function store(Request $request){return $this->service->store($request);}
    public function update(Request $request,int $ticketId){return $this->service->update($request,$ticketId);}
    public function destroy(Request $request,int $ticketId){return $this->service->destroy($request,$ticketId);}
}
