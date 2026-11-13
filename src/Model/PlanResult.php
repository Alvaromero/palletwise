<?php

declare(strict_types=1);

namespace Palletwise\Model;

use Palletwise\Support\Json;

/**
 * Output of Planner::plan(): shared metadata and one ModeResult per requested mode,
 * in request order. It keeps the request so the Verifier can check the result
 * independently.
 */
final readonly class PlanResult
{
    /**
     * @param list<ModeResult> $results
     */
    public function __construct(
        public Meta $meta,
        public array $results,
        public PlanRequest $request,
    ) {}

    public function forMode(Mode $mode): ?ModeResult
    {
        foreach ($this->results as $result) {
            if ($result->mode === $mode) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param list<ModeResult> $results
     */
    public function withResults(array $results): self
    {
        return new self($this->meta, $results, $this->request);
    }

    /**
     * @return array{meta: array<string, string>, results: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta->toArray(),
            'results' => array_map(static fn(ModeResult $r): array => $r->toArray(), $this->results),
        ];
    }

    /**
     * Deterministic JSON: the same input produces the same bytes.
     */
    public function toJson(): string
    {
        return Json::encode($this->toArray());
    }
}
