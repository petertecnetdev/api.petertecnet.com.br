<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\FinancialController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminFinancialRoutesTest extends TestCase
{
    /** @dataProvider financialRoutes */
    public function test_financial_center_contract_is_registered(string $method, string $uri, string $controllerMethod): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        $this->assertSame(FinancialController::class . '@' . $controllerMethod, $route->getActionName());
        $this->assertContains('auth:api', $route->gatherMiddleware());
    }

    public static function financialRoutes(): array
    {
        return [
            ['GET', '/api/admin/ecosystem/financial/dashboard', 'dashboard'],
            ['GET', '/api/admin/ecosystem/financial/transactions', 'transactions'],
            ['GET', '/api/admin/ecosystem/financial/transactions/1', 'transaction'],
            ['GET', '/api/admin/ecosystem/financial/orders', 'orders'],
            ['GET', '/api/admin/ecosystem/financial/payouts', 'payouts'],
            ['GET', '/api/admin/ecosystem/financial/health', 'health'],
            ['GET', '/api/admin/ecosystem/financial/ledger', 'ledger'],
            ['GET', '/api/admin/ecosystem/financial/reconciliations', 'reconciliations'],
            ['POST', '/api/admin/ecosystem/financial/reconcile', 'reconcileNow'],
            ['GET', '/api/admin/ecosystem/financial/closing', 'closing'],
            ['GET', '/api/admin/ecosystem/financial/reports/csv', 'export'],
            ['GET', '/api/admin/ecosystem/financial/reports/pdf', 'export'],
        ];
    }
}
