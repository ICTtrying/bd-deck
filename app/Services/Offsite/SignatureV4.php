<?php

namespace App\Services\Offsite;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use SensitiveParameter;

/**
 * AWS Signature Version 4, zelf geschreven omdat de AWS-SDK geen dependency van de app is.
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */
final readonly class SignatureV4
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private string $accessKeyId,
        #[SensitiveParameter] private string $secretAccessKey,
        private string $region,
        private string $service = 's3',
    ) {}

    /**
     * Geeft de headers terug die met het verzoek mee moeten, inclusief Authorization.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function sign(string $method, string $url, array $headers, string $payloadHash, DateTimeInterface $time): array
    {
        $time = DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $time->format('Ymd\THis\Z');
        $scope = $time->format('Ymd').'/'.$this->region.'/'.$this->service.'/aws4_request';

        $lowered = array_change_key_case($headers);

        if (! isset($lowered['host'])) {
            $headers['Host'] = $this->host($url);
        }

        if (! isset($lowered['x-amz-date'])) {
            $headers['X-Amz-Date'] = $amzDate;
        }

        $canonicalHeaders = [];

        foreach ($headers as $name => $value) {
            $canonicalHeaders[strtolower($name)] = preg_replace('/\s+/', ' ', trim($value));
        }

        ksort($canonicalHeaders, SORT_STRING);
        $signedHeaders = implode(';', array_keys($canonicalHeaders));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalUri($url),
            $this->canonicalQuery($url),
            implode('', array_map(fn (string $name, string $value): string => "{$name}:{$value}\n", array_keys($canonicalHeaders), $canonicalHeaders)),
            $signedHeaders,
            $payloadHash,
        ]);

        $stringToSign = implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $time->format('Ymd'), 'AWS4'.$this->secretAccessKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        $headers['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKeyId,
            $scope,
            $signedHeaders,
            hash_hmac('sha256', $stringToSign, $key),
        );

        return $headers;
    }

    private function host(string $url): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? null;
        $default = ($parts['scheme'] ?? 'https') === 'http' ? 80 : 443;

        return ($parts['host'] ?? '').($port !== null && $port !== $default ? ':'.$port : '');
    }

    /**
     * S3 normaliseert het pad niet: het al gecodeerde pad wordt letterlijk ondertekend.
     */
    private function canonicalUri(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path === '' ? '/' : $path;
    }

    private function canonicalQuery(string $url): string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if ($query === '') {
            return '';
        }

        $pairs = array_map(function (string $pair): array {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            return [rawurlencode(rawurldecode($name)), rawurlencode(rawurldecode($value))];
        }, explode('&', $query));

        usort($pairs, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn (array $pair): string => $pair[0].'='.$pair[1], $pairs));
    }
}
