<?php

declare(strict_types=1);

namespace Palletwise\Tests\Unit\Profile;

use Palletwise\Exception\InvalidProfile;
use Palletwise\Model\Mode;
use Palletwise\Model\Rotation;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Tests\Support\ArrayPath;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\WeightUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileRepositoryTest extends TestCase
{
    public function testLoadsAmazonUs(): void
    {
        $profile = (new ProfileRepository())->load('amazon-us@2026-11');

        self::assertSame('amazon-us@2026-11', $profile->key());
        self::assertSame(LengthUnit::Inch, $profile->lengthUnit);
        self::assertSame(WeightUnit::Pound, $profile->weightUnit);
        self::assertSame(48_000, $profile->palletLength);
        self::assertSame(40_000, $profile->palletWidth);
        self::assertSame(0, $profile->overhang);
        self::assertSame(5_000, $profile->tareHeight);
        self::assertSame(40_000, $profile->tareWeight);
        self::assertSame(72_000, $profile->nonStackable->maxHeight);
        self::assertSame(1_500_000, $profile->nonStackable->maxWeight);
        self::assertNotNull($profile->stackable);
        self::assertSame(50_000, $profile->stackable->maxHeight);
        self::assertSame(750_000, $profile->stackable->maxWeight);
        self::assertSame(100_000, $profile->mandatoryBoxWeightOver);
        self::assertSame(30_000, $profile->mandatoryBoxWidthOver);
        self::assertSame(100_000, $profile->mandatorySetWeightOver);
        self::assertSame(80_000, $profile->unsupportedLengthOver);
        self::assertSame(50_000, $profile->heavyBoxWarningOver);
        self::assertSame(72_000, $profile->singleUnitHeightOver);
        self::assertSame(Rotation::KeepUpright, $profile->defaultRotation);
        self::assertSame(800, $profile->minSupport);
        self::assertSame(1_000, $profile->bandTolerance);
        self::assertNull($profile->parcelMinFill);

        self::assertTrue($profile->isUnconfirmed('modes.stackable.maxWeight'));
        self::assertFalse($profile->isUnconfirmed('modes.non_stackable.maxWeight'));
        self::assertSame([], $profile->unconfirmedFor(Mode::NonStackable));
        self::assertSame(['modes.stackable.maxHeight', 'modes.stackable.maxWeight'], $profile->unconfirmedFor(Mode::Stackable));
    }

    public function testLoadsAmazonEuAsFullyUnconfirmed(): void
    {
        $profile = (new ProfileRepository())->load('amazon-eu@2026-11');

        self::assertSame(LengthUnit::Centimeter, $profile->lengthUnit);
        self::assertSame(WeightUnit::Kilogram, $profile->weightUnit);
        self::assertSame(120_000, $profile->palletLength);
        self::assertSame(100_000, $profile->palletWidth);
        self::assertSame(180_000, $profile->nonStackable->maxHeight);
        self::assertSame(500_000, $profile->nonStackable->maxWeight);
        self::assertSame(15_000, $profile->tareHeight);
        self::assertSame(25_000, $profile->tareWeight);
        self::assertNull($profile->stackable);
        self::assertTrue($profile->isUnconfirmed('pallet.length'));
        self::assertSame(['*'], $profile->unconfirmedFor(Mode::NonStackable));
    }

    public function testUnknownProfile(): void
    {
        $this->expectException(InvalidProfile::class);
        (new ProfileRepository())->load('amazon-mx@2026-11');
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(InvalidProfile::class);
        (new ProfileRepository())->load('../amazon-us@2026-11');
    }

    public function testLoadsWalmartUsWithoutMandatoryPalletRules(): void
    {
        $profile = (new ProfileRepository())->load('walmart-us@2026-11');

        self::assertSame(71_999, $profile->nonStackable->maxHeight);
        self::assertSame(2_099_999, $profile->nonStackable->maxWeight);
        self::assertNull($profile->mandatoryBoxWeightOver);
        self::assertNull($profile->mandatoryBoxWidthOver);
        self::assertNull($profile->mandatorySetWeightOver);
        self::assertNull($profile->unsupportedLengthOver);
        self::assertNull($profile->singleUnitHeightOver);
        self::assertSame(150_000, $profile->heavyBoxWarningOver);
        self::assertSame(['pallet.tareHeight', 'pallet.tareWeight'], $profile->unconfirmedFor(Mode::NonStackable));
    }

    public function testListsBundledKeys(): void
    {
        self::assertSame(['amazon-eu@2026-11', 'amazon-us@2026-11', 'walmart-us@2026-11'], (new ProfileRepository())->keys());
    }

    public function testCustomDirectory(): void
    {
        $dir = self::tempDirectoryWith('acme', '2030-01');
        try {
            $repository = (new ProfileRepository())->withDirectory($dir);
            self::assertSame('acme@2030-01', $repository->load('acme@2030-01')->key());
            self::assertSame('amazon-us@2026-11', $repository->load('amazon-us@2026-11')->key());
            self::assertContains('acme@2030-01', $repository->keys());
        } finally {
            self::removeTempDirectory($dir, 'acme', '2030-01');
        }
    }

    public function testWithoutBundledProfiles(): void
    {
        $dir = self::tempDirectoryWith('acme', '2030-01');
        try {
            $repository = (new ProfileRepository(includeBundled: false))->withDirectory($dir);
            self::assertSame(['acme@2030-01'], $repository->keys());
            $this->expectException(InvalidProfile::class);
            $repository->load('amazon-us@2026-11');
        } finally {
            self::removeTempDirectory($dir, 'acme', '2030-01');
        }
    }

    public function testCustomDirectoryCannotShadowABundledProfile(): void
    {
        $dir = self::tempDirectoryWith('amazon-us', '2026-11');
        try {
            $this->expectException(InvalidProfile::class);
            $this->expectExceptionMessage('more than one source');
            (new ProfileRepository())->withDirectory($dir)->load('amazon-us@2026-11');
        } finally {
            self::removeTempDirectory($dir, 'amazon-us', '2026-11');
        }
    }

    public function testRegistersProfilesInMemory(): void
    {
        $data = self::validProfile();
        $data['id'] = 'acme-3pl';
        $data['version'] = '2031-02';
        unset($data['mandatoryPallet'], $data['boxRules'], $data['unconfirmed']);

        $repository = (new ProfileRepository())->withProfile(ProfileRepository::parse((string) json_encode($data)));
        $profile = $repository->load('acme-3pl@2031-02');

        self::assertNull($profile->mandatoryBoxWeightOver);
        self::assertNull($profile->heavyBoxWarningOver);
        self::assertSame([], $profile->unconfirmed);
        self::assertContains('acme-3pl@2031-02', $repository->keys());
        self::assertNotContains('acme-3pl@2031-02', (new ProfileRepository())->keys(), 'the original repository is not modified');
    }

    public function testInMemoryProfileCannotShadowABundledOne(): void
    {
        $repository = (new ProfileRepository())->withProfile(ProfileRepository::parse(self::validProfile()));

        $this->expectException(InvalidProfile::class);
        $this->expectExceptionMessage('more than one source');
        $repository->load('amazon-us@2026-11');
    }

    public function testParseRejectsBadIds(): void
    {
        $data = self::validProfile();
        $data['id'] = 'Walmart US';

        $this->expectException(InvalidProfile::class);
        $this->expectExceptionMessage('id must be');
        ProfileRepository::parse($data);
    }

    public function testRejectsMissingDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProfileRepository())->withDirectory('/nonexistent/palletwise');
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidProfiles(): iterable
    {
        $v = self::validProfile();
        $set = static fn(string $path, mixed $value): array => ArrayPath::set($v, $path, $value);

        yield 'id mismatch' => [$set('id', 'other'), 'id and version'];
        yield 'unknown root field' => [$set('extra', 1), 'unknown field "extra"'];
        yield 'bad unit' => [$set('units.length', 'ft'), 'units.length'];
        yield 'negative pallet' => [$set('pallet.length', -48), 'pallet.length'];
        yield 'string number' => [$set('pallet.width', '40'), 'pallet.width'];
        yield 'overhang' => [$set('pallet.overhang', 1), 'overhang'];
        yield 'limit below tare' => [$set('modes.non_stackable.maxHeight', 4), 'must exceed the pallet tare'];
        yield 'missing mode' => [ArrayPath::remove($v, 'modes.non_stackable'), 'modes.non_stackable'];
        yield 'min support > 1' => [$set('defaults.minSupport', 1.5), 'between 0 and 1'];
        yield 'bad rotation' => [$set('defaults.rotation', 'sideways'), 'defaults.rotation'];
        yield 'unknown unconfirmed path' => [$set('unconfirmed', ['nope']), 'unknown path'];
        yield 'zero rule' => [$set('boxRules.singleUnitHeightOver', 0), 'boxRules.singleUnitHeightOver'];
        yield 'unknown rule' => [$set('boxRules.maxStack', 3), 'unknown field "boxRules.maxStack"'];
        yield 'missing defaults' => [ArrayPath::remove($v, 'defaults'), 'defaults'];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidProfiles')]
    public function testValidation(array $data, string $message): void
    {
        $this->expectException(InvalidProfile::class);
        $this->expectExceptionMessage($message);
        ProfileRepository::fromJson('amazon-us@2026-11', (string) json_encode($data));
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(InvalidProfile::class);
        ProfileRepository::fromJson('amazon-us@2026-11', '{');
    }

    private static function tempDirectoryWith(string $id, string $version): string
    {
        $dir = sys_get_temp_dir() . '/palletwise-profiles-' . bin2hex(random_bytes(4));
        mkdir($dir . '/' . $id, 0o777, true);
        $data = self::validProfile();
        $data['id'] = $id;
        $data['version'] = $version;
        file_put_contents(\sprintf('%s/%s/%s.json', $dir, $id, $version), json_encode($data));

        return $dir;
    }

    private static function removeTempDirectory(string $dir, string $id, string $version): void
    {
        unlink(\sprintf('%s/%s/%s.json', $dir, $id, $version));
        rmdir($dir . '/' . $id);
        rmdir($dir);
    }

    /**
     * @return array<mixed>
     */
    private static function validProfile(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../../../profiles/amazon-us/2026-11.json'), true);
        \assert(\is_array($data));

        return $data;
    }
}
