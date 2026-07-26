<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use RuntimeException;

/**
 * Base for every failure originating in a marketplace connector, so callers can
 * distinguish "the marketplace misbehaved" from a genuine application bug.
 */
class ConnectorException extends RuntimeException {}
