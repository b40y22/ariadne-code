export const SAMPLE_FILE = 'OrderService.php'

export const SAMPLE_CODE = `<?php

declare(strict_types=1);

namespace App;

final class OrderService extends BaseService
{
    public function __construct(private readonly OrderRepository $orders) {}

    public function createOrder(array $data): int
    {
        $this->validate($data);

        $total = 0.0;
        foreach ($data['items'] as $item) {
            if (!$this->inStock($item)) {
                continue;
            }

            try {
                $total += $this->price($item);
            } catch (PricingException $e) {
                $this->log('pricing failed');
                throw $e;
            }
        }

        return $this->orders->save($data, self::roundMoney($total));
    }

    private function validate(array $data): void
    {
        if ($data === []) {
            throw new \\InvalidArgumentException('Empty order');
        }
    }

    private function inStock(array $item): bool
    {
        return $item['qty'] > 0;
    }

    private function price(array $item): float
    {
        return $item['qty'] * $item['price'];
    }

    private static function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
`
