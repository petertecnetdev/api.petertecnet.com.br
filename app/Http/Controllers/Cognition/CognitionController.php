<?php

namespace App\Http\Controllers\Cognition;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

abstract class CognitionController extends Controller
{
    protected function authorizeCognition(Request $request): void
    {
        abort_unless(config('cognition.enabled'), 404, 'Cognitive research core is disabled.');
        abort_unless($request->user()?->hasPermission('ecosystem_manage'), 403, 'Acesso ao núcleo cognitivo não autorizado.');
    }
}
