<?php

namespace Mlc\Engine;

/** `return` inside a function block. */
final class ReturnSignal extends \RuntimeException
{
    public function __construct(public readonly mixed $value = null)
    {
        parent::__construct();
    }
}
