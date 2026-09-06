<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EstablishmentEventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminEstablishmentTicketRoutesTest extends TestCase
{
    /** @dataProvider ticketRoutes */
    public function test_admin_event_ticket_contract_is_registered(string $method, string $uri, string $controllerMethod): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        $this->assertSame(EstablishmentEventController::class . '@' . $controllerMethod, $route->getActionName());
        $this->assertContains('auth:api', $route->gatherMiddleware());
    }

    public static function ticketRoutes(): array
    {
        return [
            ['GET', '/api/admin/ecosystem/establishments/1/resources/events/2/tickets', 'tickets'],
            ['POST', '/api/admin/ecosystem/establishments/1/resources/events/2/tickets', 'storeTicket'],
            ['PUT', '/api/admin/ecosystem/establishments/1/resources/events/2/tickets/3', 'updateTicket'],
        ];
    }
}
