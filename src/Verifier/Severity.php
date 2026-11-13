<?php

declare(strict_types=1);

namespace Palletwise\Verifier;

enum Severity: string
{
    /** Hard rule: the plan is invalid. */
    case Hard = 'hard';

    /** Soft rule: the plan is valid, but a warning is raised. */
    case Soft = 'soft';
}
