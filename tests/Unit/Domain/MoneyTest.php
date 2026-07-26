<?php

declare(strict_types=1);

use App\Domain\Pricing\Money;

it('reads decimal strings as they arrive from postgres numeric', function () {
    expect(Money::fromNumericString('1299.90')->cents)->toBe(129990)
        ->and(Money::fromNumericString('0.05')->cents)->toBe(5)
        ->and(Money::fromNumericString('42')->cents)->toBe(4200)
        ->and(Money::fromNumericString('-15.50')->cents)->toBe(-1550);
});

it('rounds the third decimal the same way numeric(12,2) does', function () {
    expect(Money::fromNumericString('19.999')->cents)->toBe(2000)
        ->and(Money::fromNumericString('19.994')->cents)->toBe(1999)
        ->and(Money::fromNumericString('19.995')->cents)->toBe(2000);
});

it('round trips through the database representation', function (string $amount) {
    expect(Money::fromNumericString($amount)->toNumericString())->toBe($amount);
})->with(['0.00', '9.99', '1299.90', '999999.99']);

it('never loses cents when subtracting, unlike float arithmetic', function () {
    $previous = Money::fromNumericString('0.30');
    $current = Money::fromNumericString('0.10');

    // 0.3 - 0.1 === 0.2 is false in float arithmetic; this must not be.
    expect($previous->minus($current)->equals(Money::fromNumericString('0.20')))->toBeTrue();
});

it('compares amounts by cents', function () {
    $cheap = Money::fromNumericString('10.00');
    $expensive = Money::fromNumericString('10.01');

    expect($cheap->isLessThan($expensive))->toBeTrue()
        ->and($expensive->isGreaterThan($cheap))->toBeTrue()
        ->and($cheap->equals(Money::fromCents(1000)))->toBeTrue();
});

it('formats as brazilian currency for messages and cards', function () {
    expect(Money::fromNumericString('1299.90')->format())->toBe('R$ 1.299,90')
        ->and(Money::fromNumericString('0.99')->format())->toBe('R$ 0,99');
});

it('rejects values that are not monetary amounts', function () {
    Money::fromNumericString('R$ 12,90');
})->throws(InvalidArgumentException::class);

it('accepts a float only at the payload boundary', function () {
    expect(Money::fromFloat(1299.9)->toNumericString())->toBe('1299.90')
        ->and(Money::fromFloat(0.1 + 0.2)->toNumericString())->toBe('0.30');
});
