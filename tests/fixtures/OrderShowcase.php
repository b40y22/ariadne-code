<?php

declare(strict_types=1);

namespace App;

/**
 * The demo class shown when the UI opens.
 *
 * Every construct the analyzer understands appears here, so a new feature is visible as soon as it exists:
 * add it to this file, regenerate the snapshot (`make snapshots`) and open a method in the UI.
 */
final class OrderService extends BaseService
{
    public function __construct(private readonly OrderRepository $orders) {}

    /** foreach, continue, try/catch, throw, a callback passed to a call, return. */
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

        return DB::transaction(fn () => $this->orders->save($data, self::roundMoney($total)));
    }

    /** `??=`, `??`, `&&` and a ternary: branches inside a single expression. */
    public function discount(array $order): float
    {
        $this->rates ??= $this->loadRates();

        $rate = $order['coupon'] ?? $this->defaultRate();
        $vip = $this->isVip($order['user']) && $this->hasHistory($order['user']);

        return $vip ? $rate * 2 : $this->clamp($rate);
    }

    /** switch with stacked cases, fall-through and default, then a match with a throwing arm. */
    public function ship(array $order): string
    {
        switch ($order['method']) {
            case 'courier':
            case 'post':
                $this->printLabel($order);
                break;
            case 'pickup':
                $this->reserveShelf($order);
            default:
                $this->notify($order);
        }

        return match ($order['region']) {
            'eu', 'uk' => $this->euRate($order),
            'us' => $this->usRate($order),
            default => throw new UnsupportedRegion($order['region']),
        };
    }

    /** `throw new ...` shows its expression; `throw Factory::make()` is the call step, then a bare `throw`. */
    private function validate(array $data): void
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Empty order');
        }

        if (!$this->isKnownCustomer($data)) {
            throw OrderException::unknownCustomer($data['customer']);
        }
    }

    /** A call on a fresh object keeps its parentheses; a returned call is the step, then a bare `return`. */
    private function stamp(): string
    {
        return (new Clock())->now()->format('c');
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

/** A plain function: it is its own node, with its own flow, and the script below calls it. */
function format_money(float $amount): string
{
    return number_format($amount, 2) . ' UAH';
}

// Legacy-style script: the code of the file that runs outside any class or function.
require_once __DIR__ . '/bootstrap.php';

if (!isset($_POST['items'])) {
    http_response_code(400);
    die('No items');
}

$id = new OrderService(new OrderRepository())->createOrder($_POST);

echo format_money($id);
