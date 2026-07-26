<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Publishing\Channel;
use App\Domain\Publishing\PublicationStatus;
use App\Models\Promotion;
use App\Models\Publication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Publication>
 */
final class PublicationFactory extends Factory
{
    protected $model = Publication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory(),
            'channel' => Channel::WhatsApp,
            'provider' => 'meta',
            'recipient' => fake()->numerify('+5511#########'),
            'status' => PublicationStatus::Pending,
            'message_body' => null,
            'card_path' => null,
            'attempts' => 0,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::Sent,
            'sent_at' => now(),
            'attempts' => 1,
            'provider_message_id' => fake()->uuid(),
        ]);
    }

    public function failed(string $code = 'rate_limited'): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::Failed,
            'attempts' => 3,
            'error_code' => $code,
            'error_message' => 'Provider rejected the message',
        ]);
    }

    /**
     * Deferred to the next allowed publishing window rather than discarded.
     */
    public function scheduled(?string $for = null): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::Scheduled,
            'scheduled_for' => $for === null ? now()->addHours(2) : $for,
        ]);
    }

    public function onChannel(Channel $channel): static
    {
        return $this->state(fn (): array => ['channel' => $channel]);
    }
}
