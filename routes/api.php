<?php

use App\Http\Controllers\HandleProviderRpcController;
use Illuminate\Support\Facades\Route;

Route::post('/rpc', HandleProviderRpcController::class)
    ->middleware('throttle:provider-rpc')
    ->name('rpc.handle');
