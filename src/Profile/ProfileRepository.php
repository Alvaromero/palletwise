<?php

declare(strict_types=1);

namespace Palletwise\Profile;

use Palletwise\Exception\InvalidProfile;
use Palletwise\Model\ModeLimits;
use Palletwise\Model\Profile;
use Palletwise\Model\Rotation;
use Palletwise\Units\Decimal;
use Palletwise\Units\LengthUnit;
use Palletwise\Units\Rounding;
use Palletwise\Units\WeightUnit;

/**
 * Loads and validates versioned profiles ("amazon-us@2026-11" → <directory>/amazon-us/2026-11.json).
 *
 * A repository combines several sources: the bundled profiles (unless disabled), any number
 * of extra directories with the same layout, and profiles registered in memory. Instances are
 * immutable; withDirectory() and withProfile() return a new repository. A key must be defined
 * by exactly one source: a custom profile cannot shadow a bundled one, so a given key always
 * means the same rules. It is the library's only source of I/O.
 */
final class ProfileRepository
{
    private const KEY_PATTERN = '/^([a-z0-9][a-z0-9-]{0,62})@(\d{4}-\d{2})$/D';
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/D';
    private const VERSION_PATTERN = '/^\d{4}-\d{2}$/D';

    /** Paths that `unconfirmed` may declare. */
    public const PATHS = [
        'pallet.length', 'pallet.width', 'pallet.overhang', 'pallet.tareHeight', 'pallet.tareWeight',
        'modes.non_stackable.maxHeight', 'modes.non_stackable.maxWeight',
        'modes.stackable.maxHeight', 'modes.stackable.maxWeight',
        'mandatoryPallet.boxWeightOver', 'mandatoryPallet.boxWidthOver', 'mandatoryPallet.setWeightOver',
        'boxRules.unsupportedLengthOver', 'boxRules.heavyBoxWarningOver', 'boxRules.singleUnitHeightOver',
        'defaults.rotation', 'defaults.minSupport', 'defaults.bandTolerance', 'defaults.parcelMinFill',
    ];

    /** @var list<string> */
    private array $directories;

    /** @var array<string, Profile> profiles registered in memory */
    private array $registered = [];

    /** @var array<string, Profile> */
    private array $cache = [];

    /**
     * @param bool $includeBundled whether the profiles shipped with the library are available
     */
    public function __construct(bool $includeBundled = true)
    {
        $this->directories = $includeBundled ? [self::bundledDirectory()] : [];
    }

    public static function bundledDirectory(): string
    {
        return \dirname(__DIR__, 2) . '/profiles';
    }

    /**
     * A copy that also reads profiles from `<directory>/<id>/<version>.json`.
     */
    public function withDirectory(string $directory): self
    {
        $directory = rtrim($directory, '/');
        if ($directory === '' || !is_dir($directory)) {
            throw new \InvalidArgumentException(\sprintf('Profile directory "%s" does not exist.', $directory));
        }
        $copy = clone $this;
        if (!\in_array($directory, $copy->directories, true)) {
            $copy->directories[] = $directory;
        }
        $copy->cache = [];

        return $copy;
    }

    /**
     * A copy that also offers the given profile (for example, one built with parse() from a
     * database row).
     *
     * @throws InvalidProfile if another profile with the same key is already registered
     */
    public function withProfile(Profile $profile): self
    {
        $key = $profile->key();
        if (isset($this->registered[$key])) {
            throw InvalidProfile::duplicated($key);
        }
        $copy = clone $this;
        $copy->registered[$key] = $profile;
        $copy->cache = [];

        return $copy;
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * @throws InvalidProfile if the profile does not exist, is defined more than once or is invalid
     */
    public function load(string $key): Profile
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        if (preg_match(self::KEY_PATTERN, $key, $m) !== 1) {
            throw InvalidProfile::notFound($key);
        }

        $files = [];
        foreach ($this->directories as $directory) {
            $path = \sprintf('%s/%s/%s.json', $directory, $m[1], $m[2]);
            if (is_file($path)) {
                $files[] = $path;
            }
        }
        $sources = \count($files) + (isset($this->registered[$key]) ? 1 : 0);
        if ($sources === 0) {
            throw InvalidProfile::notFound($key);
        }
        if ($sources > 1) {
            throw InvalidProfile::duplicated($key);
        }
        if (isset($this->registered[$key])) {
            return $this->cache[$key] = $this->registered[$key];
        }

        $raw = is_readable($files[0]) ? file_get_contents($files[0]) : false;
        if ($raw === false) {
            throw InvalidProfile::notFound($key);
        }

        return $this->cache[$key] = self::fromJson($key, $raw);
    }

    /**
     * Keys of every profile available in this repository, sorted. Files are listed, not
     * validated: load() still checks each one.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->registered);
        foreach ($this->directories as $directory) {
            foreach (glob($directory . '/*/*.json') ?: [] as $path) {
                $key = basename(\dirname($path)) . '@' . basename($path, '.json');
                if (self::isValidKey($key)) {
                    $keys[] = $key;
                }
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Validates and builds a profile from its JSON or decoded array, taking the key from its
     * `id` and `version`.
     *
     * @param string|array<mixed> $data
     *
     * @throws InvalidProfile
     */
    public static function parse(string|array $data): Profile
    {
        if (\is_string($data)) {
            try {
                $data = json_decode($data, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw InvalidProfile::invalid('(unknown)', 'not valid JSON.');
            }
            if (!\is_array($data)) {
                throw InvalidProfile::invalid('(unknown)', 'root must be an object.');
            }
        }
        $id = $data['id'] ?? null;
        $version = $data['version'] ?? null;
        if (!\is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw InvalidProfile::invalid('(unknown)', 'id must be lowercase letters, digits and dashes (for example "walmart-us").');
        }
        if (!\is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw InvalidProfile::invalid($id, 'version must look like "YYYY-MM".');
        }

        return self::fromArray($id . '@' . $version, $data);
    }

    /**
     * Validates and builds a profile from its JSON.
     *
     * @throws InvalidProfile
     */
    public static function fromJson(string $key, string $json): Profile
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidProfile::invalid($key, 'not valid JSON.');
        }
        if (!\is_array($data)) {
            throw InvalidProfile::invalid($key, 'root must be an object.');
        }

        return self::fromArray($key, $data);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidProfile
     */
    public static function fromArray(string $key, array $data): Profile
    {
        $r = new ProfileReader($key, $data);
        $r->keys('', ['id', 'version', 'description', 'units', 'pallet', 'modes', 'mandatoryPallet', 'boxRules', 'defaults', 'unconfirmed']);

        $id = $r->string('id');
        $version = $r->string('version');
        if ($id . '@' . $version !== $key) {
            throw InvalidProfile::invalid($key, \sprintf('id and version declare "%s@%s".', $id, $version));
        }
        $description = $r->has('description') ? $r->string('description') : '';

        $r->keys('units', ['length', 'weight']);
        $lengthUnit = LengthUnit::tryFrom($r->string('units.length')) ?? throw InvalidProfile::invalid($key, 'units.length must be in, cm or mm.');
        $weightUnit = WeightUnit::tryFrom($r->string('units.weight')) ?? throw InvalidProfile::invalid($key, 'units.weight must be lb or kg.');

        $r->keys('pallet', ['length', 'width', 'overhang', 'tareHeight', 'tareWeight']);
        $palletLength = $r->positive('pallet.length', Rounding::Floor);
        $palletWidth = $r->positive('pallet.width', Rounding::Floor);
        $overhang = $r->nonNegative('pallet.overhang', Rounding::Floor);
        if ($overhang !== 0) {
            throw InvalidProfile::invalid($key, 'pallet.overhang must be 0 (overhang is not supported in v1).');
        }
        $tareHeight = $r->nonNegative('pallet.tareHeight', Rounding::Ceil);
        $tareWeight = $r->nonNegative('pallet.tareWeight', Rounding::Ceil);

        $r->keys('modes', ['non_stackable', 'stackable']);
        $nonStackable = self::modeLimits($r, $key, 'non_stackable', $tareHeight, $tareWeight);
        $stackable = $r->has('modes.stackable') ? self::modeLimits($r, $key, 'stackable', $tareHeight, $tareWeight) : null;

        // Optional sections: a missing section or field, or null, means the profile has no such rule.
        if ($r->has('mandatoryPallet') && !$r->isNull('mandatoryPallet')) {
            $r->keys('mandatoryPallet', ['boxWeightOver', 'boxWidthOver', 'setWeightOver']);
        }
        if ($r->has('boxRules') && !$r->isNull('boxRules')) {
            $r->keys('boxRules', ['unsupportedLengthOver', 'heavyBoxWarningOver', 'singleUnitHeightOver']);
        }
        $r->keys('defaults', ['rotation', 'minSupport', 'bandTolerance', 'parcelMinFill']);

        $rotation = Rotation::tryFrom($r->string('defaults.rotation')) ?? throw InvalidProfile::invalid($key, 'defaults.rotation is not valid.');
        $minSupport = $r->positive('defaults.minSupport', Rounding::Ceil);
        $parcelMinFill = self::optional($r, 'defaults.parcelMinFill', Rounding::Ceil);
        if ($minSupport > Decimal::SCALE || ($parcelMinFill !== null && $parcelMinFill > Decimal::SCALE)) {
            throw InvalidProfile::invalid($key, 'defaults.minSupport and defaults.parcelMinFill must be between 0 and 1.');
        }

        $unconfirmed = $r->has('unconfirmed') ? $r->stringList('unconfirmed') : [];
        foreach ($unconfirmed as $path) {
            if ($path !== '*' && !\in_array($path, self::PATHS, true)) {
                throw InvalidProfile::invalid($key, \sprintf('unconfirmed contains unknown path "%s".', $path));
            }
        }

        return new Profile(
            id: $id,
            version: $version,
            description: $description,
            lengthUnit: $lengthUnit,
            weightUnit: $weightUnit,
            palletLength: $palletLength,
            palletWidth: $palletWidth,
            overhang: $overhang,
            tareHeight: $tareHeight,
            tareWeight: $tareWeight,
            nonStackable: $nonStackable,
            stackable: $stackable,
            mandatoryBoxWeightOver: self::optional($r, 'mandatoryPallet.boxWeightOver', Rounding::Floor),
            mandatoryBoxWidthOver: self::optional($r, 'mandatoryPallet.boxWidthOver', Rounding::Floor),
            mandatorySetWeightOver: self::optional($r, 'mandatoryPallet.setWeightOver', Rounding::Floor),
            unsupportedLengthOver: self::optional($r, 'boxRules.unsupportedLengthOver', Rounding::Floor),
            heavyBoxWarningOver: self::optional($r, 'boxRules.heavyBoxWarningOver', Rounding::Floor),
            singleUnitHeightOver: self::optional($r, 'boxRules.singleUnitHeightOver', Rounding::Floor),
            defaultRotation: $rotation,
            minSupport: $minSupport,
            bandTolerance: $r->nonNegative('defaults.bandTolerance', Rounding::Floor),
            parcelMinFill: $parcelMinFill,
            unconfirmed: $unconfirmed,
        );
    }

    /**
     * A positive number, or null if the field is missing or null.
     */
    private static function optional(ProfileReader $r, string $path, Rounding $rounding): ?int
    {
        if (!$r->has($path) || $r->isNull($path)) {
            return null;
        }

        return $r->positive($path, $rounding);
    }

    private static function modeLimits(ProfileReader $r, string $key, string $mode, int $tareHeight, int $tareWeight): ModeLimits
    {
        $r->keys('modes.' . $mode, ['maxHeight', 'maxWeight']);
        $limits = new ModeLimits(
            $r->positive(\sprintf('modes.%s.maxHeight', $mode), Rounding::Floor),
            $r->positive(\sprintf('modes.%s.maxWeight', $mode), Rounding::Floor),
        );
        if ($limits->maxHeight <= $tareHeight || $limits->maxWeight <= $tareWeight) {
            throw InvalidProfile::invalid($key, \sprintf('modes.%s limits must exceed the pallet tare.', $mode));
        }

        return $limits;
    }
}
