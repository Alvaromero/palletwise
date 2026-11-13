<?php

declare(strict_types=1);

namespace Palletwise\Planner;

use Palletwise\Exception\EngineInvariantViolation;
use Palletwise\Exception\InvalidPlanRequest;
use Palletwise\Exception\InvalidProfile;
use Palletwise\Model\Meta;
use Palletwise\Model\ModeResult;
use Palletwise\Model\PlanRequest;
use Palletwise\Model\PlanResult;
use Palletwise\Model\Warning;
use Palletwise\Model\WarningCode;
use Palletwise\Palletwise;
use Palletwise\Profile\ProfileRepository;
use Palletwise\Profile\ResolvedProfile;
use Palletwise\Verifier\PlanVerifier;
use Palletwise\Verifier\Verifier;
use Palletwise\Verifier\Violation;
use Palletwise\Verifier\ViolationCode;

/**
 * Entry point: computes a pallet plan for each requested mode.
 *
 * Every result goes through the Verifier before being returned. If it breaks a hard rule,
 * EngineInvariantViolation is thrown (it is an engine bug); soft rules are added as
 * warnings.
 */
final class Planner
{
    private readonly ProfileRepository $profiles;
    private readonly PlanVerifier $verifier;
    private readonly ModePlanner $modePlanner;

    /**
     * @param list<MixedStrategy>|null $strategies mixed-stage variants; defaults to
     *                                             MixedStrategy::all(). Intended for tests.
     */
    public function __construct(?ProfileRepository $profiles = null, ?PlanVerifier $verifier = null, ?array $strategies = null)
    {
        $this->profiles = $profiles ?? new ProfileRepository();
        $this->verifier = $verifier ?? new Verifier();
        $this->modePlanner = new ModePlanner(new ShipmentPlanner($strategies));
    }

    /**
     * @throws InvalidPlanRequest        if the request is invalid (including an unknown profile or mode)
     * @throws EngineInvariantViolation  if the generated plan fails verification
     */
    public function plan(PlanRequest $request): PlanResult
    {
        try {
            $profile = $this->profiles->load($request->profile);
        } catch (InvalidProfile) {
            throw InvalidPlanRequest::because(\sprintf('Unknown or invalid profile "%s".', $request->profile));
        }
        $resolved = ResolvedProfile::resolve($profile, $request->lengthUnit, $request->weightUnit, $request->overrides);
        foreach ($request->modes as $mode) {
            $resolved->limits($mode);
            $resolved->assertUsable($mode);
        }

        $results = [];
        foreach ($request->modes as $mode) {
            $results[] = $this->modePlanner->plan($request, $resolved, $mode);
        }

        $meta = new Meta(
            Palletwise::VERSION,
            $profile->id,
            $profile->version,
            $request->lengthUnit->value,
            $request->weightUnit->value,
            $request->hash(),
        );
        $result = new PlanResult($meta, $results, $request);

        $violations = $this->verifier->verify($result, $profile);
        $hard = array_values(array_filter($violations, static fn(Violation $v): bool => $v->isHard()));
        if ($hard !== []) {
            throw new EngineInvariantViolation($hard);
        }

        $final = [];
        foreach ($result->results as $modeResult) {
            $modeResult = $modeResult->withAddedWarnings(self::softWarnings($violations, $modeResult));
            $final[] = $request->includePlacements ? $modeResult : $modeResult->withoutPlacements();
        }

        return $result->withResults($final);
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Warning>
     */
    private static function softWarnings(array $violations, ModeResult $result): array
    {
        $shipments = [];
        foreach ($result->pallets as $pallet) {
            $shipments[$pallet->index] = $pallet->shipmentId;
        }
        $warnings = [];
        foreach ($violations as $v) {
            if ($v->isHard() || $v->mode !== $result->mode) {
                continue;
            }
            [$code, $message] = match ($v->code) {
                ViolationCode::CenterOfGravityOffset => [WarningCode::CenterOfGravityOffset, 'The center of gravity of the pallet is offset from its center.'],
                default => [WarningCode::HeavyOnLight, 'Some boxes rest on lighter boxes.'],
            };
            $details = [];
            foreach ($v->values as $key => $value) {
                $details[$key] = \is_int($value) && $key !== 'pairs' ? $value / 1000 : $value;
            }
            $warnings[] = new Warning(
                $code,
                $message,
                $v->palletIndex === null ? null : ($shipments[$v->palletIndex] ?? null),
                $v->boxId,
                $v->palletIndex,
                $details,
            );
        }

        return $warnings;
    }
}
