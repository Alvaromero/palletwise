<?php

declare(strict_types=1);

namespace Palletwise\Model;

enum PalletType: string
{
    case SingleSku = 'single_sku';
    case Mixed = 'mixed';
    case Mandatory = 'mandatory';
}
