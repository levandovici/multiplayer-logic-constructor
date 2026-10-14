<?php

namespace Mlc\Engine;

/** Step/loop budget exceeded — pathological program, hard-fail the action. */
final class BudgetExhausted extends \RuntimeException
{
}
