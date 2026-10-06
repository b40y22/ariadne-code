<?php

declare(strict_types=1);

namespace Fixtures;

final class OrderService extends BaseService
{
    public function __construct(private readonly OrderRepository $orders) {}

    public function createOrder(array $data): int
    {
        $this->validate($data);
        $total = $this->calculateTotal($data);
        $this->log('order created');

        return $this->orders->save($data, $total);
    }

    private function validate(array $data): void
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Empty order');
        }
    }

    private function calculateTotal(array $data): float
    {
        return self::roundMoney(array_sum($data));
    }

    private static function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
