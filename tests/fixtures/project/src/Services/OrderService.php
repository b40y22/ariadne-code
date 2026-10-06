<?php

declare(strict_types=1);

namespace Shop\Services;

use Illuminate\Support\Facades\Log;
use Shop\Mail\Mailer;
use Shop\Repositories\OrderRepository;

class OrderService
{
    /** Legacy style: no declared types, assigned from typed constructor parameters. */
    public $repository;

    public $mailer;

    public function __construct(OrderRepository $repository, Mailer $mailer)
    {
        $this->repository = $repository;
        $this->mailer = $mailer;
    }

    public function place(array $items): array
    {
        $total = $this->total($items);

        if ($total <= 0) {
            Log::warning('Empty order');

            return ['id' => null];
        }

        $order = $this->repository->create(['total' => $total]);
        $this->mailer->send($order['id'], 'Order placed');

        return $order;
    }

    private function total(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $total += $item['price'] * $item['quantity'];
        }

        return $total;
    }
}
