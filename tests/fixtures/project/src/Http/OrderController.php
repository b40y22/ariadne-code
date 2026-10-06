<?php

declare(strict_types=1);

namespace Shop\Http;

use Illuminate\Http\Request;
use Shop\Services\OrderService;

final class OrderController extends Controller
{
    /** A promoted constructor parameter types `$this->orders`. */
    public function __construct(private readonly OrderService $orders) {}

    public function store(Request $request): array
    {
        $this->validate($request);

        $order = $this->orders->place($request->input('items'));

        return ['id' => $order['id']];
    }
}
