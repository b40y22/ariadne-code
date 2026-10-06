<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * What a method or static call is made on, as far as the syntax tells.
 */
final readonly class Receiver
{
    /**
     * @param string|null $name The class for `ClassName`, the property or variable name for `Property` and `Variable`.
     */
    public function __construct(public ReceiverKind $kind, public ?string $name = null) {}
}
