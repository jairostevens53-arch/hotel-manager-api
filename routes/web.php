<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-web-route', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'NO LLEGO',
        'header_authorization' => $request->header('Authorization'),
    ]);
});