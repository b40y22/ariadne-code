<?php

declare(strict_types=1);

use Shop\Http\OrderController;

/** The demo project analyzed when the UI opens in project mode: several files that call each other. */
$router->post('/orders', [OrderController::class, 'store']);
