<?php

declare(strict_types=1);

namespace Shop\Mail;

final class Mailer
{
    public function send(?int $orderId, string $subject): void
    {
        // Assigned only `new Message()`, so `$message` is known to be a Message.
        $message = new Message(sprintf('#%d %s', $orderId, $subject));

        $this->deliver($message->body());
    }

    private function deliver(string $text): void
    {
        error_log($text);
    }
}
