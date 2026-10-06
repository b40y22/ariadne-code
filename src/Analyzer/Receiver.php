<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * What a method or static call is made on, as far as the syntax tells: a base, and the properties read and
 * methods called on the way. `$this->billing->invoices->show()` is `This` with the path `billing`, `invoices`;
 * `$this->repo->find($id)->save()` is `This` with `repo`, `find()`; `Order::query()->get()` is the class
 * `Order` with `query()`.
 */
final readonly class Receiver
{
    /**
     * @param string|null $name The class for `ClassName`, the variable name for `Variable`.
     * @param list<string> $path Steps from the base, in order: a property name, or a method name followed by `()`.
     */
    public function __construct(public ReceiverKind $kind, public ?string $name = null, public array $path = []) {}
}
