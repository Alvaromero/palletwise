<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Support;

use Palletwise\Support\Json;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    public function testEncodesDeterministically(): void
    {
        $value = ['b' => 0.1, 'a' => [1, 2.5, 'x/y', null, true], 'c' => 1 / 3, 'd' => -0.0, 'e' => 12.0];
        self::assertSame('{"b":0.1,"a":[1,2.5,"x/y",null,true],"c":0.333333,"d":0,"e":12}', Json::encode($value));
    }

    public function testIndependentOfSerializePrecision(): void
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');
        try {
            self::assertSame('[0.1]', Json::encode([0.1]));
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }
    }

    public function testSortKeysRecursively(): void
    {
        self::assertSame(['a' => ['x' => 1, 'y' => 2], 'b' => [3, 1]], Json::sortKeys(['b' => [3, 1], 'a' => ['y' => 2, 'x' => 1]]));
    }
}
