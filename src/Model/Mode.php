<?php

declare(strict_types=1);

namespace Palletwise\Model;

enum Mode: string
{
    case NonStackable = 'non_stackable';
    case Stackable = 'stackable';
}
