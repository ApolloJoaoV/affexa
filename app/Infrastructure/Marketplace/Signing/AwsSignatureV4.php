<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace\Signing;

use Carbon\CarbonImmutable;

/**
 * AWS Signature Version 4, as required by the Product Advertising API.
 *
 * Amazon rejects an unsigned or missigned request outright, so this is not
 * optional plumbing. It is isolated from the connector because signing is pure,
 * fully deterministic and therefore testable without any network.
 */
final class AwsSignatureV4
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service,
    ) {}

    /**
     * Returns the headers to send, including Authorization.
     *
     * @param  array<string, string>  $headers  headers that participate in the signature
     * @return array<string, string>
     */
    public function sign(
        string $method,
        string $host,
        string $path,
        string $payload,
        array $headers = [],
        ?CarbonImmutable $now = null,
    ): array {
        $now ??= CarbonImmutable::now('UTC');
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $headers = array_change_key_case($headers) + [
            'host' => $host,
            'x-amz-date' => $amzDate,
        ];

        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            // Values are trimmed and inner whitespace collapsed, per the spec.
            $canonicalHeaders .= $name.':'.preg_replace('/\s+/', ' ', trim($value))."\n";
        }

        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            '', // No query string: PA-API sends everything in the body.
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $payload),
        ]);

        $scope = implode('/', [$dateStamp, $this->region, $this->service, 'aws4_request']);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));

        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature,
        );

        return $headers + ['Authorization' => $authorization];
    }

    /**
     * The four-step key derivation: secret to date, to region, to service, to
     * request. Each step signs the previous digest, which is what scopes a leaked
     * signature to one day, one region and one service.
     */
    public function signingKey(string $dateStamp): string
    {
        $dateKey = hash_hmac('sha256', $dateStamp, 'AWS4'.$this->secretKey, binary: true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, binary: true);
        $serviceKey = hash_hmac('sha256', $this->service, $regionKey, binary: true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, binary: true);
    }
}
