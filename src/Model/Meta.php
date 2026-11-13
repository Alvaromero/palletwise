<?php

declare(strict_types=1);

namespace Palletwise\Model;

final readonly class Meta
{
    public function __construct(
        public string $libraryVersion,
        public string $profile,
        public string $profileVersion,
        public string $lengthUnit,
        public string $weightUnit,
        public string $inputHash,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'libraryVersion' => $this->libraryVersion,
            'profile' => $this->profile,
            'profileVersion' => $this->profileVersion,
            'lengthUnit' => $this->lengthUnit,
            'weightUnit' => $this->weightUnit,
            'inputHash' => $this->inputHash,
        ];
    }
}
