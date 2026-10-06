<?php

declare(strict_types=1);

namespace Fixtures;

final class ShippingService
{
    public function ship(array $items): bool
    {
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (!$this->inStock($item)) {
                continue;
            }

            try {
                $this->pack($item);
            } catch (PackingException $e) {
                $this->report($e);
                break;
            } finally {
                $this->release($item);
            }
        }

        return $this->dispatch();
    }

    private function inStock(string $item): bool
    {
        return $item !== '';
    }

    private function pack(string $item): void {}

    private function report(PackingException $e): void {}

    private function release(string $item): void {}

    private function dispatch(): bool
    {
        return true;
    }
}
