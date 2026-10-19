<?php

namespace App\Services\Offsite;

use App\Exceptions\OffsiteException;
use Closure;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;
use SimpleXMLElement;
use Throwable;

/**
 * Minimale S3-client (AWS, Backblaze B2, Cloudflare R2, Wasabi, MinIO) via de Http-client.
 * Bestanden gaan altijd als stream: back-ups kunnen gigabytes groot zijn.
 */
final readonly class S3Storage
{
    /**
     * Kleinste deel dat S3 accepteert, behalve het laatste.
     */
    public const int MIN_PART_SIZE = 5 * 1024 * 1024;

    /**
     * S3 staat maximaal 10.000 delen per upload toe.
     */
    private const int MAX_PARTS = 10_000;

    private const string EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const int ATTEMPTS = 3;

    private SignatureV4 $signer;

    public function __construct(
        private string $endpoint,
        string $region,
        private string $bucket,
        string $accessKeyId,
        #[SensitiveParameter] string $secretAccessKey,
        private bool $virtualHosted = false,
        private int $partSize = 16 * 1024 * 1024,
    ) {
        $this->signer = new SignatureV4($accessKeyId, $secretAccessKey, $region);
    }

    /**
     * @param  array<string, string>  $metadata
     */
    public function put(string $key, string $path, array $metadata = []): void
    {
        $size = (int) filesize($path);
        $headers = $this->metadataHeaders($metadata);

        if ($size <= $this->partSize) {
            $hash = (string) hash_file('sha256', $path);

            $this->retrying(fn (): Response => $this->send('PUT', $key, [], $headers, $this->fileStream($path), $hash));

            return;
        }

        $this->putMultipart($key, $path, $size, $headers);
    }

    public function putString(string $key, string $contents): void
    {
        $this->retrying(fn (): Response => $this->send('PUT', $key, [], [], $contents, hash('sha256', $contents)));
    }

    public function getString(string $key): string
    {
        return $this->retrying(fn (): Response => $this->send('GET', $key))->body();
    }

    /**
     * Schrijft het object direct naar schijf, zonder het in het geheugen te laden.
     */
    public function download(string $key, string $path): void
    {
        $this->retrying(fn (): Response => $this->send('GET', $key, sink: $path));
    }

    /**
     * @return array{size: int, last_modified: ?Carbon, metadata: array<string, string>}|null
     */
    public function head(string $key): ?array
    {
        $response = $this->retrying(fn (): Response => $this->send('HEAD', $key, allowMissing: true));

        if ($response->status() === 404) {
            return null;
        }

        $metadata = [];

        foreach ($response->headers() as $name => $values) {
            if (str_starts_with(strtolower($name), 'x-amz-meta-')) {
                $metadata[substr(strtolower($name), 11)] = rawurldecode((string) ($values[0] ?? ''));
            }
        }

        $modified = $response->header('Last-Modified');

        return [
            'size' => (int) $response->header('Content-Length'),
            'last_modified' => $modified !== '' ? Carbon::parse($modified) : null,
            'metadata' => $metadata,
        ];
    }

    /**
     * Een object dat al weg is telt ook als verwijderd.
     */
    public function delete(string $key): void
    {
        $this->retrying(fn (): Response => $this->send('DELETE', $key, allowMissing: true));
    }

    /**
     * @return list<array{key: string, size: int, last_modified: ?Carbon}>
     */
    public function list(string $prefix): array
    {
        $objects = [];
        $token = null;

        do {
            $query = ['list-type' => '2', 'prefix' => $prefix] + ($token !== null ? ['continuation-token' => $token] : []);
            $xml = $this->xml($this->retrying(fn (): Response => $this->send('GET', '', $query)));

            foreach ($xml->Contents as $item) {
                $objects[] = [
                    'key' => (string) $item->Key,
                    'size' => (int) $item->Size,
                    'last_modified' => (string) $item->LastModified !== '' ? Carbon::parse((string) $item->LastModified) : null,
                ];
            }

            $token = (string) $xml->IsTruncated === 'true' && (string) $xml->NextContinuationToken !== ''
                ? (string) $xml->NextContinuationToken
                : null;
        } while ($token !== null);

        return $objects;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function putMultipart(string $key, string $path, int $size, array $headers): void
    {
        $partSize = max($this->partSize, (int) ceil($size / self::MAX_PARTS));
        $uploadId = (string) $this->xml($this->retrying(fn (): Response => $this->send('POST', $key, ['uploads' => ''], $headers)))->UploadId;

        if ($uploadId === '') {
            throw new OffsiteException(__('De opslag startte de upload niet.'));
        }

        try {
            $parts = [];

            for ($number = 1, $offset = 0; $offset < $size; $number++, $offset += $partSize) {
                $length = min($partSize, $size - $offset);
                $hash = $this->hashRange($path, $offset, $length);

                $response = $this->retrying(fn (): Response => $this->send(
                    'PUT',
                    $key,
                    ['partNumber' => (string) $number, 'uploadId' => $uploadId],
                    [],
                    new LimitStream($this->fileStream($path), $length, $offset),
                    $hash,
                ));

                $parts[$number] = $response->header('ETag');
            }

            $body = '<CompleteMultipartUpload>'.implode('', array_map(
                fn (int $number, string $etag): string => '<Part><PartNumber>'.$number.'</PartNumber><ETag>'.htmlspecialchars($etag, ENT_XML1).'</ETag></Part>',
                array_keys($parts),
                $parts,
            )).'</CompleteMultipartUpload>';

            $response = $this->retrying(fn (): Response => $this->send('POST', $key, ['uploadId' => $uploadId], ['Content-Type' => 'application/xml'], $body, hash('sha256', $body)));

            // S3 kan een 200 met een fout in de body teruggeven
            if (str_contains($response->body(), '<Error>')) {
                throw OffsiteException::fromResponse($response, $this->bucket);
            }
        } catch (Throwable $exception) {
            rescue(fn () => $this->send('DELETE', $key, ['uploadId' => $uploadId], allowMissing: true), report: false);

            throw $exception;
        }
    }

    /**
     * Tijdelijke netwerk- en serverfouten opnieuw proberen; een foute sleutel of bucket niet.
     *
     * @param  Closure(): Response  $request
     */
    private function retrying(Closure $request): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $request();
            } catch (OffsiteException $exception) {
                if (! $exception->isTemporary() || $attempt >= self::ATTEMPTS) {
                    throw $exception;
                }

                Sleep::for(500 * 2 ** $attempt)->milliseconds();
            }
        }
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     */
    private function send(
        string $method,
        string $key,
        array $query = [],
        array $headers = [],
        StreamInterface|string|null $body = null,
        string $payloadHash = self::EMPTY_PAYLOAD_HASH,
        ?string $sink = null,
        bool $allowMissing = false,
    ): Response {
        $url = $this->url($key, $query);
        $contentType = $headers['Content-Type'] ?? 'application/octet-stream';
        unset($headers['Content-Type']);

        $headers['x-amz-content-sha256'] = $payloadHash;
        $signed = $this->signer->sign($method, $url, $headers, $payloadHash, Carbon::now());
        // Guzzle zet de Host-header zelf, op basis van dezelfde URL
        unset($signed['Host']);

        $request = Http::withHeaders($signed)->connectTimeout(15)->timeout(3600);

        if ($body !== null) {
            $request = $request->withBody($body, $contentType);
        }

        if ($sink !== null) {
            $request = $request->sink($sink);
        }

        try {
            $response = $request->send($method, $url);
        } catch (ConnectionException $exception) {
            throw OffsiteException::unreachable($this->endpoint, $exception);
        }

        if ($response->successful() || ($allowMissing && $response->status() === 404)) {
            return $response;
        }

        if ($sink !== null && is_file($sink)) {
            // een foutmelding van de opslag mag niet als back-up op schijf blijven staan
            $body = (string) file_get_contents($sink, length: 65536);
            @unlink($sink);
            $response = new Response(new PsrResponse($response->status(), $response->headers(), $body));
        }

        throw OffsiteException::fromResponse($response, $this->bucket);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function url(string $key, array $query = []): string
    {
        $parts = parse_url(rtrim($this->endpoint, '/'));
        $scheme = $parts['scheme'] ?? 'https';
        $host = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $encodedKey = implode('/', array_map(rawurlencode(...), explode('/', $key)));

        // een bucket met punten past niet in het TLS-certificaat van bucket.s3.amazonaws.com
        if ($this->virtualHosted && ! str_contains($this->bucket, '.')) {
            $url = "{$scheme}://{$this->bucket}.{$host}/{$encodedKey}";
        } else {
            $path = rtrim($parts['path'] ?? '', '/').'/'.rawurlencode($this->bucket);
            $url = "{$scheme}://{$host}{$path}".($key !== '' ? '/'.$encodedKey : '');
        }

        ksort($query, SORT_STRING);

        return $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, string>
     */
    private function metadataHeaders(array $metadata): array
    {
        $headers = [];

        foreach ($metadata as $name => $value) {
            // headers mogen alleen ASCII bevatten; sitenamen niet altijd
            $headers['x-amz-meta-'.strtolower($name)] = rawurlencode($value);
        }

        return $headers;
    }

    private function fileStream(string $path): StreamInterface
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Kan {$path} niet openen.");
        }

        return Utils::streamFor($handle);
    }

    private function hashRange(string $path, int $offset, int $length): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Kan {$path} niet openen.");
        }

        fseek($handle, $offset);
        $context = hash_init('sha256');

        while ($length > 0 && ! feof($handle)) {
            $chunk = (string) fread($handle, min(1024 * 1024, $length));
            hash_update($context, $chunk);
            $length -= strlen($chunk);
        }

        fclose($handle);

        return hash_final($context);
    }

    private function xml(Response $response): SimpleXMLElement
    {
        $xml = simplexml_load_string($response->body(), options: LIBXML_NONET);

        if ($xml === false) {
            throw new OffsiteException(__('De opslag gaf een antwoord dat BD Deck niet begrijpt.'));
        }

        return $xml;
    }
}
