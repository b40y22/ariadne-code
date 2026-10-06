<?php

declare(strict_types=1);

namespace Shop\Mail;

final class Message
{
    public function __construct(private readonly string $text) {}

    public function body(): string
    {
        return trim($this->text);
    }
}
