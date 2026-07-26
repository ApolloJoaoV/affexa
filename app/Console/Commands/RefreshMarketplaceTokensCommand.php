<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Marketplace\Exceptions\ConnectorException;
use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Marketplace\MarketplaceConnectorManager;
use App\Infrastructure\Persistence\MarketplaceTokenRepository;
use Illuminate\Console\Command;

/**
 * Renews access tokens before they expire.
 *
 * Preemptive rather than reactive: a token dying halfway through a paginated fetch
 * would abandon the run, and retrying it re-reads pages already processed.
 */
final class RefreshMarketplaceTokensCommand extends Command
{
    protected $signature = 'promohub:tokens:refresh
                            {--minutes= : Renew tokens expiring within this many minutes}';

    protected $description = 'Renew marketplace access tokens that are close to expiring';

    public function handle(
        MarketplaceTokenRepository $tokens,
        MarketplaceConnectorManager $connectors,
        AdvisoryLock $locks,
    ): int {
        $minutes = $this->option('minutes');
        $marketplaces = $tokens->marketplacesNeedingRefresh($minutes === null ? null : (int) $minutes);

        if ($marketplaces->isEmpty()) {
            $this->components->info('No tokens are close to expiring.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($marketplaces as $marketplace) {
            /*
             * Two workers refreshing the same marketplace do not merely duplicate
             * work: each rotation invalidates the other's brand new token, and the
             * marketplace ends up with no usable credential at all. The lock makes
             * the loser skip rather than compete.
             */
            try {
                $outcome = $locks->attempt("token_refresh:{$marketplace->id}", function () use ($marketplace, $connectors): string {
                    $connectors->for($marketplace)->refreshToken();

                    return 'renewed';
                });
            } catch (ConnectorException $exception) {
                $failures++;
                $this->components->error("{$marketplace->slug}: {$exception->getMessage()}");

                continue;
            }

            $this->components->twoColumnDetail(
                $marketplace->slug,
                $outcome === null ? 'skipped, another worker holds the lock' : 'renewed',
            );
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
