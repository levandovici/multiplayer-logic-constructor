<?php

namespace Mlc\Engine;

// Local variable scope for one trigger/function invocation. Object (not a
// bare array) so nested calls share it by reference semantics.
final class Scope
{
    /** @var array<string,mixed> */
    public array $vars = [];

    /** Approximate live bytes of $vars — Executor keeps it in step with
     *  every write and hard-fails past Context::MAX_SCOPE_BYTES. */
    public int $bytes = 0;
}
