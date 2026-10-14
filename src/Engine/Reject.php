<?php

namespace Mlc\Engine;

/** A `reject` block fired — the action fails, the transaction rolls back. */
final class Reject extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
