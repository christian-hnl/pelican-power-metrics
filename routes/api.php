<?php

use ChristianHnl\PowerMetrics\Http\Controllers\PushController;
use Illuminate\Support\Facades\Route;

Route::post('/push', PushController::class)->name('power-metrics.push');
