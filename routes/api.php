<?php

use App\Http\Controllers\ProxyController;
use App\Http\Middleware\AuthenticateToken;
use Illuminate\Support\Facades\Route;

Route::post('/v1/messages', [ProxyController::class, 'handle'])
    ->middleware(AuthenticateToken::class);
