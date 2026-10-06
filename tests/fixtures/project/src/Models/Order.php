<?php

declare(strict_types=1);

namespace Shop\Models;

final class Order
{
    public function __construct(public readonly int $id) {}

    public function markPaid(): void
    {
        error_log("Order {$this->id} paid");
    }
}
