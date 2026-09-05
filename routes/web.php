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
    if (strtolower((string) session('peter_admin_email')) === 'petertecnet@gmail.com') return redirect()->route('admin.center');
    return view('admin.gate');
})->name('admin.gate');

Route::post('/admin/access', function (\Illuminate\Http\Request $request) {
    $email = strtolower(trim((string) $request->validate(['email' => ['required','email']])['email']));
    if ($email !== 'petertecnet@gmail.com') {
        $return = session()->pull('peter_admin_return_url', 'https://petertecnet.com.br/');
        return redirect()->away($return)->withErrors(['email' => 'Acesso não autorizado.']);
    }
    $request->session()->regenerate();
    $request->session()->put('peter_admin_email', $email);
    return redirect()->route('admin.center');
})->middleware('throttle:8,1')->name('admin.gate.verify');

Route::get('/admin', fn () => view('admin.index'))
    ->middleware(\App\Http\Middleware\PeterTecnetAdmin::class)
    ->name('admin.center');

Route::get('/admin/logout', function (\Illuminate\Http\Request $request) {
    $request->session()->forget('peter_admin_email');
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('https://petertecnet.com.br/');
})->name('admin.logout');
