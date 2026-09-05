<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('api.landing');

Route::view('/docs', 'api-docs')->name('api.docs');

Route::get('/api', function () {
    return response()->json([
        'name' => 'Peter Tecnet API',
        'description' => 'API pública e multiplataforma para integrações com o ecossistema Peter Tecnet.',
        'visibility' => 'public',
        'format' => 'JSON',
        'transport' => 'HTTPS',
        'base_url' => url('/api'),
        'documentation' => url('/docs'),
        'website' => 'https://petertecnet.com.br',
        'authentication' => 'Bearer token em endpoints protegidos',
    ]);
})->name('api.discovery');

Route::get('/login', function () {
    return response()->json(['message' => 'Usuário não autenticado']);
})->name('login');


Route::get('/admin/access', function () {
    $webUser = \Illuminate\Support\Facades\Auth::guard('web')->user();
    if ($webUser && strtolower((string) $webUser->email) === 'petertecnet@gmail.com') return redirect()->route('admin.center');
    return view('admin.gate');
})->name('admin.gate');

Route::post('/admin/access', function (\Illuminate\Http\Request $request) {
    $credentials = $request->validate(['email' => ['required','email'], 'password' => ['required','string']]);
    $email = strtolower(trim((string) $credentials['email']));
    if ($email !== 'petertecnet@gmail.com' || ! \Illuminate\Support\Facades\Auth::guard('web')->attempt(['email' => $email, 'password' => $credentials['password']])) {
        \Illuminate\Support\Facades\Auth::guard('web')->logout();
        $return = session()->pull('peter_admin_return_url', 'https://petertecnet.com.br/');
        return redirect()->away($return);
    }
    $request->session()->regenerate();
    return redirect()->route('admin.center');
})->middleware('throttle:5,1')->name('admin.gate.verify');

Route::get('/admin', fn () => view('admin.index'))
    ->middleware(\App\Http\Middleware\PeterTecnetAdmin::class)
    ->name('admin.center');

Route::get('/admin/logout', function (\Illuminate\Http\Request $request) {
    \Illuminate\Support\Facades\Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('https://petertecnet.com.br/');
})->name('admin.logout');
