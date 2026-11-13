<?php

declare(strict_types=1);

namespace Palletwise\Exception;

/**
 * The request is invalid. The message names the affected field in terms of the
 * input and never exposes engine internals.
 */
final class InvalidPlanRequest extends \InvalidArgumentException implements PalletwiseException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
