<?php

namespace Mlc\Engine;

// Local variable scope for one trigger/function invocation. Object (not a
// bare array) so nested calls share it by reference semantics.
final class Scope
{
    /** @var array<string,mixed> */
    public array $vars = [];
}
