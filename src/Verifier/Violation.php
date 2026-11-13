<?php

declare(strict_types=1);

namespace Palletwise\Verifier;

use Palletwise\Model\Mode;

/**
 * A violation detected by the Verifier. Numeric values in `values` are integers
 * scaled ×1000 in the request units (areas ×10^6).
 */
final readonly class Violation
{
    public Severity $severity;

    /**
     * @param array<string, int|string|null> $values
     */
    public function __construct(
        public ViolationCode $code,
        public ?Mode $mode,
        public ?int $palletIndex = null,
        public ?string $boxId = null,
        public array $values = [],
    ) {
        $this->severity = $code->severity();
    }

    public function isHard(): bool
    {
        return $this->severity === Severity::Hard;
    }

    public function __toString(): string
    {
        $parts = [$this->code->value];
        if ($this->mode !== null) {
            $parts[] = 'mode=' . $this->mode->value;
        }
        if ($this->palletIndex !== null) {
            $parts[] = 'pallet=' . $this->palletIndex;
        }
        if ($this->boxId !== null) {
            $parts[] = 'box=' . $this->boxId;
        }
        foreach ($this->values as $key => $value) {
            $parts[] = $key . '=' . ($value ?? 'null');
        }

        return implode(' ', $parts);
    }
}
