<?php

declare(strict_types=1);

namespace Shop\Repositories;

use Shop\Models\Order;

final class OrderRepository extends BaseRepository
{
    /** The return type lets a chain like `$repository->find($id)->markPaid()` reach `Order`. */
    public function find(int $id): Order
    {
        return new Order($id);
    }
}
