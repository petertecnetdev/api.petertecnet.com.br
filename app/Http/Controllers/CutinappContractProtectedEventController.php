<?php

namespace App\Http\Controllers;

use App\Services\CutinappProducerContractService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CutinappContractProtectedEventController extends CutinappEventController
{
    public function store(Request $request)
    {
        $productionId = (int) $request->input('production_id');
        if ($productionId > 0) {
            $signed = DB::table('cutinapp_producer_contract_acceptances')
                ->where('production_id', $productionId)
                ->where('user_id', $request->user()->id)
                ->where('contract_version', CutinappProducerContractService::VERSION)
                ->exists();

            abort_unless($signed, 428, 'Antes de criar o primeiro evento desta produção, leia e assine o Termo de Adesão do Produtor.');
        }

        return parent::store($request);
    }
}
