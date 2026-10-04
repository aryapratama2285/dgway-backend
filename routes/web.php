<?php

use Illuminate\Support\Facades\Route;

// Project A is API-only. The Flutter app is the only client.
Route::get('/', fn () => response()->json(['name' => 'DG Way Owner Operating API', 'api' => '/api/v1']));
