<?php

namespace Mlc\Engine;

/** A `respond` block fired — sets the API response, halts the trigger chain. */
final class RespondSignal extends \RuntimeException
{
}
