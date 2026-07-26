<?php

declare(strict_types=1);

use App\Domain\Publishing\Channel;
use App\Infrastructure\Persistence\PublicationWindowGuard;
use App\Models\Product;
use App\Models\PublicationWindow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The republication rule is a database guarantee, not an application check.
 * These tests exist to prove that claim, because an application level check is
 * exactly what two concurrent workers defeat.
 */

beforeEach(function () {
    $this->guard = new PublicationWindowGuard;
    $this->product = Product::factory()->create();
});

it('grants the first reservation for a product and channel', function () {
    expect($this->guard->reserve($this->product->id, Channel::WhatsApp, 360))->toBeTrue()
        ->and(PublicationWindow::where('product_id', $this->product->id)->count())->toBe(1);
});

it('refuses a second reservation while the window is live', function () {
    $this->guard->reserve($this->product->id, Channel::WhatsApp, 360);

    // Rejected, not thrown: a duplicate is an expected outcome of the pipeline.
    expect($this->guard->reserve($this->product->id, Channel::WhatsApp, 360))->toBeFalse()
        ->and(PublicationWindow::where('product_id', $this->product->id)->count())->toBe(1);
});

it('allows the same product on a different channel', function () {
    $this->guard->reserve($this->product->id, Channel::WhatsApp, 360);

    expect($this->guard->reserve($this->product->id, Channel::Telegram, 360))->toBeTrue();
});

it('allows a different product on the same channel', function () {
    $other = Product::factory()->create();

    $this->guard->reserve($this->product->id, Channel::WhatsApp, 360);

    expect($this->guard->reserve($other->id, Channel::WhatsApp, 360))->toBeTrue();
});

it('allows a new reservation once the previous window has elapsed', function () {
    // A window that closed an hour ago must not block anything.
    DB::insert(
        'INSERT INTO publication_windows (product_id, channel, "window") VALUES (?, ?, tstzrange(now() - interval \'3 hours\', now() - interval \'1 hour\'))',
        [$this->product->id, Channel::WhatsApp->value]
    );

    expect($this->guard->reserve($this->product->id, Channel::WhatsApp, 360))->toBeTrue();
});

it('rejects the insert at the constraint level, not merely in application code', function () {
    $this->guard->reserve($this->product->id, Channel::WhatsApp, 360);

    // Bypassing the guard entirely still fails: the rule lives in the schema.
    DB::insert(
        'INSERT INTO publication_windows (product_id, channel, "window") VALUES (?, ?, tstzrange(now(), now() + interval \'6 hours\'))',
        [$this->product->id, Channel::WhatsApp->value]
    );
})->throws(QueryException::class, 'excl_publication_windows_no_overlap');

it('reports whether a product is currently blocked on a channel', function () {
    expect($this->guard->isBlocked($this->product->id, Channel::WhatsApp))->toBeFalse();

    $this->guard->reserve($this->product->id, Channel::WhatsApp, 360);

    expect($this->guard->isBlocked($this->product->id, Channel::WhatsApp))->toBeTrue()
        ->and($this->guard->isBlocked($this->product->id, Channel::Telegram))->toBeFalse();
});

it('leaves the surrounding transaction usable after a rejected reservation', function () {
    $this->guard->reserve($this->product->id, Channel::WhatsApp, 360);

    // The publish path claims the window inside a transaction that also updates
    // the promotion. Without a savepoint the rejected insert would abort that
    // transaction and every later statement in it would fail.
    DB::transaction(function () {
        expect($this->guard->reserve($this->product->id, Channel::WhatsApp, 360))->toBeFalse();

        $this->product->update(['title' => 'Still writable after the rejection']);
    });

    expect($this->product->refresh()->title)->toBe('Still writable after the rejection');
});

it('refuses an empty window', function () {
    DB::insert(
        'INSERT INTO publication_windows (product_id, channel, "window") VALUES (?, ?, tstzrange(now(), now()))',
        [$this->product->id, Channel::WhatsApp->value]
    );
})->throws(QueryException::class, 'chk_publication_windows_window_not_empty');
