<?php

declare(strict_types=1);

namespace Palletwise\Verifier;

use Palletwise\Model\PlanResult;
use Palletwise\Model\Profile;

interface PlanVerifier
{
    /**
     * @return list<Violation>
     */
    public function verify(PlanResult $result, Profile $profile): array;
}
