<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'name' => config('app.name', 'Smart Account POS API'),
        'version' => '1.0.0',
        'status' => 'operational',
        'region' => 'sin1 (Singapore)',
        'endpoints' => [
            'health' => '/api/v1/health',
            'api' => '/api/v1',
        ],
    ]);
});
