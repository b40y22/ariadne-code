<?php

declare(strict_types=1);

namespace Shop\Mail;

final class Mailer
{
    public function send(?int $orderId, string $subject): void
    {
        $this->deliver(sprintf('#%d %s', $orderId, $subject));
    }

    private function deliver(string $text): void
    {
        error_log($text);
    }
}
