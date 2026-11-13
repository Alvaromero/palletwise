<?php

declare(strict_types=1);

namespace Palletwise\Exception;

use Palletwise\Verifier\Violation;

/**
 * The plan produced by the engine does not pass the Verifier. This is an engine bug:
 * an invalid plan is never returned.
 */
final class EngineInvariantViolation extends \LogicException implements PalletwiseException
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(private readonly array $violations)
    {
        $codes = array_values(array_unique(array_map(static fn(Violation $v): string => $v->code->value, $violations)));
        parent::__construct(\sprintf(
            'Internal planning error: the generated plan failed verification (%s).',
            implode(', ', $codes),
        ));
    }

    /**
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
