<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * What a method or static call is made on, as far as the syntax tells: a base, and the properties read from it
 * on the way. `$this->billing->invoices->show()` is `This` with the path `billing`, `invoices`.
 */
final readonly class Receiver
{
    /**
     * @param string|null $name The class for `ClassName`, the variable name for `Variable`.
     * @param list<string> $path Properties read from the base, in order.
     */
    public function __construct(public ReceiverKind $kind, public ?string $name = null, public array $path = []) {}
}
