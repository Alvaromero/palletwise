<?php

declare(strict_types=1);

namespace Palletwise\Exception;

/**
 * A bundled or custom profile does not exist or fails validation.
 */
final class InvalidProfile extends \RuntimeException implements PalletwiseException
{
    public static function notFound(string $key): self
    {
        return new self(\sprintf('Profile "%s" was not found.', $key));
    }

    public static function duplicated(string $key): self
    {
        return new self(\sprintf('Profile "%s" is defined by more than one source; a custom profile cannot reuse an existing key.', $key));
    }

    public static function invalid(string $key, string $reason): self
    {
        return new self(\sprintf('Profile "%s" is invalid: %s', $key, $reason));
    }
}
