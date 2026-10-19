<?php

namespace Tests\Support;

use App\Services\Offsite\SignatureV4;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Een bucket in het geheugen achter Http::fake(), die ook elke handtekening narekent.
 */
class FakeS3
{
    /**
     * @var array<string, array{body: string, metadata: array<string, string>, modified: Carbon}>
     */
    public array $objects = [];

    /**
     * @var array<string, array<int, string>>
     */
    public array $uploads = [];

    /**
     * @var list<string>
     */
    public array $log = [];

    public ?Closure $interceptor = null;

    public function __construct(
        public string $bucket = 'bd-test',
        public string $endpoint = 'https://s3.eu-central-003.backblazeb2.com',
        public string $region = 'eu-central-003',
        public string $accessKeyId = 'AKIDTEST',
        public string $secretAccessKey = 'geheim-test-sleutel',
    ) {}

    public function fake(): static
    {
        Http::fake(fn (Request $request): PromiseInterface => $this->handle($request));

        return $this;
    }

    /**
     * @param  array<string, string>  $metadata
     */
    public function seed(string $key, string $body, array $metadata = [], ?Carbon $modified = null): void
    {
        $this->objects[$key] = ['body' => $body, 'metadata' => $metadata, 'modified' => $modified ?? now()];
    }

    public function handle(Request $request): PromiseInterface
    {
        $psr = $request->toPsrRequest();
        $method = $psr->getMethod();
        $this->log[] = $method.' '.$psr->getUri()->getPath().($psr->getUri()->getQuery() !== '' ? '?'.$psr->getUri()->getQuery() : '');

        if ($this->interceptor !== null && ($response = ($this->interceptor)($request)) !== null) {
            return $response;
        }

        $body = (string) $psr->getBody();

        if (! $this->signatureIsValid($request, $body)) {
            return $this->error(403, 'SignatureDoesNotMatch');
        }

        $path = rawurldecode($psr->getUri()->getPath());
        parse_str($psr->getUri()->getQuery(), $query);

        if (! str_starts_with($path.'/', '/'.$this->bucket.'/')) {
            return $this->error(404, 'NoSuchBucket');
        }

        $key = ltrim(substr($path, strlen($this->bucket) + 1), '/');

        return match (true) {
            $method === 'GET' && $key === '' => $this->listObjects((string) ($query['prefix'] ?? ''), (string) ($query['continuation-token'] ?? '')),
            $method === 'POST' && array_key_exists('uploads', $query) => $this->initiate($key, $request),
            $method === 'PUT' && isset($query['partNumber']) => $this->uploadPart((string) $query['uploadId'], (int) $query['partNumber'], $body),
            $method === 'POST' && isset($query['uploadId']) => $this->complete($key, (string) $query['uploadId'], $body),
            $method === 'DELETE' && isset($query['uploadId']) => $this->abort((string) $query['uploadId']),
            $method === 'PUT' => $this->store($key, $body, $this->metadata($request)),
            $method === 'GET' => isset($this->objects[$key]) ? Http::response($this->objects[$key]['body']) : $this->error(404, 'NoSuchKey'),
            $method === 'HEAD' => $this->head($key),
            $method === 'DELETE' => $this->delete($key),
            default => $this->error(400, 'NotImplemented'),
        };
    }

    public function error(int $status, string $code): PromiseInterface
    {
        return Http::response('<?xml version="1.0" encoding="UTF-8"?><Error><Code>'.$code.'</Code><Message>fout</Message></Error>', $status);
    }

    private function signatureIsValid(Request $request, string $body): bool
    {
        $authorization = $request->header('Authorization')[0] ?? '';

        if (preg_match('/Credential=([^\/]+)\/[^,]+, SignedHeaders=([^,]+), Signature=([0-9a-f]+)/', $authorization, $matches) !== 1 || $matches[1] !== $this->accessKeyId) {
            return false;
        }

        $payloadHash = $request->header('x-amz-content-sha256')[0] ?? '';

        if ($payloadHash !== hash('sha256', $body)) {
            return false;
        }

        $headers = [];

        foreach (explode(';', $matches[2]) as $name) {
            $headers[$name] = $request->header($name)[0] ?? '';
        }

        $time = Carbon::createFromFormat('Ymd\THis\Z', $headers['x-amz-date'], 'UTC');
        $signed = (new SignatureV4($this->accessKeyId, $this->secretAccessKey, $this->region))->sign($request->method(), $request->url(), $headers, $payloadHash, $time);

        return str_ends_with($signed['Authorization'], 'Signature='.$matches[3]);
    }

    /**
     * @return array<string, string>
     */
    private function metadata(Request $request): array
    {
        $metadata = [];

        foreach ($request->headers() as $name => $values) {
            if (str_starts_with(strtolower($name), 'x-amz-meta-')) {
                $metadata[substr(strtolower($name), 11)] = $values[0];
            }
        }

        return $metadata;
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function store(string $key, string $body, array $metadata): PromiseInterface
    {
        $this->objects[$key] = ['body' => $body, 'metadata' => $metadata, 'modified' => now()];

        return Http::response('', 200, ['ETag' => '"'.md5($body).'"']);
    }

    private function head(string $key): PromiseInterface
    {
        if (! isset($this->objects[$key])) {
            return Http::response('', 404);
        }

        $headers = ['Content-Length' => (string) strlen($this->objects[$key]['body']), 'Last-Modified' => $this->objects[$key]['modified']->toRfc7231String()];

        foreach ($this->objects[$key]['metadata'] as $name => $value) {
            $headers['x-amz-meta-'.$name] = $value;
        }

        return Http::response('', 200, $headers);
    }

    private function delete(string $key): PromiseInterface
    {
        unset($this->objects[$key]);

        return Http::response('', 204);
    }

    private function listObjects(string $prefix, string $token): PromiseInterface
    {
        $keys = array_values(array_filter(array_keys($this->objects), fn (string $key): bool => str_starts_with($key, $prefix)));
        sort($keys);

        // twee per pagina, zodat het vervolgtoken getest wordt
        $offset = (int) $token;
        $page = array_slice($keys, $offset, 2);
        $truncated = $offset + 2 < count($keys);

        $xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        foreach ($page as $key) {
            $xml .= '<Contents><Key>'.htmlspecialchars($key, ENT_XML1).'</Key><Size>'.strlen($this->objects[$key]['body']).'</Size><LastModified>'.$this->objects[$key]['modified']->toIso8601ZuluString().'</LastModified></Contents>';
        }
        $xml .= '<IsTruncated>'.($truncated ? 'true' : 'false').'</IsTruncated>'.($truncated ? '<NextContinuationToken>'.($offset + 2).'</NextContinuationToken>' : '').'</ListBucketResult>';

        return Http::response($xml);
    }

    private function initiate(string $key, Request $request): PromiseInterface
    {
        $id = 'upload-'.count($this->uploads);
        $this->uploads[$id] = [];
        $this->objects['__meta__'.$id] = ['body' => $key, 'metadata' => $this->metadata($request), 'modified' => now()];

        return Http::response('<InitiateMultipartUploadResult><Bucket>'.$this->bucket.'</Bucket><Key>'.$key.'</Key><UploadId>'.$id.'</UploadId></InitiateMultipartUploadResult>');
    }

    private function uploadPart(string $uploadId, int $number, string $body): PromiseInterface
    {
        $this->uploads[$uploadId][$number] = $body;

        return Http::response('', 200, ['ETag' => '"'.md5($body).'"']);
    }

    private function complete(string $key, string $uploadId, string $body): PromiseInterface
    {
        preg_match_all('#<PartNumber>(\d+)</PartNumber><ETag>([^<]+)</ETag>#', $body, $matches, PREG_SET_ORDER);
        $parts = $this->uploads[$uploadId];
        ksort($parts);

        foreach ($matches as [, $number, $etag]) {
            if (html_entity_decode($etag) !== '"'.md5($parts[(int) $number] ?? '').'"') {
                return $this->error(400, 'InvalidPart');
            }
        }

        $metadata = $this->objects['__meta__'.$uploadId]['metadata'];
        unset($this->objects['__meta__'.$uploadId], $this->uploads[$uploadId]);
        $this->objects[$key] = ['body' => implode('', $parts), 'metadata' => $metadata, 'modified' => now()];

        return Http::response('<CompleteMultipartUploadResult><Key>'.$key.'</Key></CompleteMultipartUploadResult>');
    }

    private function abort(string $uploadId): PromiseInterface
    {
        unset($this->uploads[$uploadId], $this->objects['__meta__'.$uploadId]);

        return Http::response('', 204);
    }
}
