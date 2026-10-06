<?php

declare(strict_types=1);

namespace Shop\Http;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Inherited by every controller: `$this->validate()` in a child resolves here. */
    protected function validate(Request $request): void
    {
        if (!$request->has('items')) {
            throw new \InvalidArgumentException('No items.');
        }
    }
}
