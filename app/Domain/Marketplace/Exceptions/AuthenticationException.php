<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

/**
 * Credentials were rejected, or no usable token could be obtained. Permanent until
 * an operator intervenes, so retrying is pointless.
 */
final class AuthenticationException extends ConnectorException {}
