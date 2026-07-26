<?php

declare(strict_types=1);

namespace App\Application\Actions;

use App\Domain\Promotion\PromotionConfidence;
use App\Domain\Promotion\PromotionStatus;
use App\Domain\Promotion\RejectionReason;
use App\Domain\Promotion\Scoring\PromotionContext;
use App\Domain\Promotion\Scoring\ScoreResult;
use App\Domain\Promotion\Scoring\ScoringEngine;
use App\Infrastructure\Persistence\PriceHistoryRepository;
use App\Infrastructure\Persistence\PromotionRepository;
use App\Models\Product;
use App\Models\Promotion;
use App\Settings\ScoringSettings;
use RuntimeException;

/**
 * Judges one product and records the verdict.
 *
 * Every evaluation is written down, including the rejections. A rejected promotion
 * with its breakdown is what makes retuning the weights an analysis rather than a
 * guess, so nothing is silently discarded.
 */
final class EvaluatePromotionAction
{
    public function __construct(
        private readonly ScoringEngine $engine,
        private readonly PriceHistoryRepository $priceHistory,
        private readonly PromotionRepository $promotions,
        private readonly ScoringSettings $settings,
    ) {}

    public function execute(Product $product): ?Promotion
    {
        if (! $product->in_stock) {
            return $this->reject($product, RejectionReason::OutOfStock);
        }

        $context = $this->buildContext($product);
        $result = $this->engine->score($context, $this->settings->toWeights());
        $confidence = $context->confidence();

        $rejection = $this->rejectionFor($context, $result, $confidence);

        if ($rejection !== null) {
            return $this->persist($product, $context, $result, $confidence, PromotionStatus::Rejected, $rejection);
        }

        return $this->persist($product, $context, $result, $confidence, PromotionStatus::Pending, null);
    }

    private function buildContext(Product $product): PromotionContext
    {
        return new PromotionContext(
            productId: $product->id,
            currentPrice: $product->current_price ?? throw new RuntimeException("Product {$product->id} has no price."),
            previousPrice: $product->previous_price,
            discountPercent: $product->discount_percent,
            history: $this->priceHistory->aggregatesFor($product->id),
            marketplaceTrustScore: $product->marketplace->trust_score,
            rating: $product->rating,
            reviewsCount: $product->reviews_count,
            isPrime: $product->is_prime,
            hasFreeShipping: $product->has_free_shipping,
            inStock: $product->in_stock,
            lowestPriceEver: $product->lowest_price_ever,
        );
    }

    /**
     * The order matters: the cheapest checks come first so an obviously
     * unqualified product is dismissed without consulting the score.
     */
    private function rejectionFor(
        PromotionContext $context,
        ScoreResult $result,
        PromotionConfidence $confidence,
    ): ?RejectionReason {
        $weights = $this->settings->toWeights();

        if ($context->discountPercent < $weights->minimumDiscountPercent) {
            return RejectionReason::BelowMinimumDiscount;
        }

        if (! $context->history->hasHistory()) {
            return RejectionReason::InsufficientHistory;
        }

        $percentBelowMedian = $context->percentBelowMedian();

        if ($percentBelowMedian !== null && $percentBelowMedian <= 0) {
            return RejectionReason::NotBelowHistoricalMedian;
        }

        if ($result->score < $this->settings->minimum_score_for_manual_review) {
            return RejectionReason::BelowMinimumScore;
        }

        return null;
    }

    /**
     * Confidence, not score, decides whether a human must look.
     *
     * A thin history can produce a spectacular score from three data points, so a
     * low confidence promotion is always queued for approval regardless of how
     * well it scored.
     */
    private function statusFor(int $score, PromotionConfidence $confidence): PromotionStatus
    {
        if (! $confidence->allowsAutomaticPublication()) {
            return PromotionStatus::Pending;
        }

        return $score >= $this->settings->minimum_score_for_automatic_publication
            ? PromotionStatus::Approved
            : PromotionStatus::Pending;
    }

    private function persist(
        Product $product,
        PromotionContext $context,
        ScoreResult $result,
        PromotionConfidence $confidence,
        PromotionStatus $status,
        ?RejectionReason $rejection,
    ): ?Promotion {
        $status = $status === PromotionStatus::Rejected
            ? PromotionStatus::Rejected
            : $this->statusFor($result->score, $confidence);

        $attributes = [
            'product_id' => $product->id,
            'marketplace_id' => $product->marketplace_id,
            'price' => $product->current_price,
            'previous_price' => $product->previous_price,
            'discount_percent' => $product->discount_percent,
            'score' => $result->score,
            'score_breakdown' => $result->toArray(),
            'confidence' => $confidence,
            'status' => $status,
            'rejection_reason' => $rejection,
            'evaluated_at' => now(),
            'approved_at' => $status === PromotionStatus::Approved ? now() : null,
            'dedupe_hash' => $this->dedupeHash($product),
        ];

        // Null when a live promotion already covers this deal; the repository
        // lets the dedupe index arbitrate without poisoning the transaction.
        return $this->promotions->createUnlessDuplicate($attributes);
    }

    private function reject(Product $product, RejectionReason $reason): ?Promotion
    {
        return $this->promotions->createUnlessDuplicate([
            'product_id' => $product->id,
            'marketplace_id' => $product->marketplace_id,
            'price' => $product->current_price,
            'previous_price' => $product->previous_price,
            'discount_percent' => $product->discount_percent,
            'score' => 0,
            'score_breakdown' => ['skipped' => $reason->value],
            'confidence' => PromotionConfidence::Low,
            'status' => PromotionStatus::Rejected,
            'rejection_reason' => $reason,
            'evaluated_at' => now(),
            'dedupe_hash' => $this->dedupeHash($product),
        ]);
    }

    /**
     * Identifies this deal: the same product at the same price is the same offer,
     * however many times it is captured.
     */
    private function dedupeHash(Product $product): string
    {
        return '\x'.hash('sha256', implode('|', [
            $product->id,
            $product->current_price?->toNumericString() ?? '0',
            $product->discount_percent,
        ]));
    }
}
