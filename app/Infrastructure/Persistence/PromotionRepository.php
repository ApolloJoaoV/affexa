<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Models\Promotion;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class PromotionRepository
{
    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    /**
     * Records an evaluation, unless a live promotion already covers the same deal.
     *
     * The partial unique index on dedupe_hash is the arbiter: two workers can
     * evaluate the same product at the same instant, and exactly one of them wins.
     * Losing is an ordinary outcome, so it returns null rather than throwing.
     *
     * The insert runs inside a transaction so that a rejected attempt is contained.
     * A failed statement aborts the whole PostgreSQL transaction — every later
     * statement would raise "current transaction is aborted" — and swallowing the
     * violation without a savepoint would poison whatever the caller does next.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createUnlessDuplicate(array $attributes): ?Promotion
    {
        try {
            return $this->connection()->transaction(
                static fn (): Promotion => Promotion::query()->create($attributes)
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
