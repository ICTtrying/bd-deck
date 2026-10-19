<?php

use App\Services\Offsite\SignatureV4;

// testvectoren uit de AWS-documentatie (sigv4 test suite "get-vanilla" en de S3-voorbeelden)
it('ondertekent de get-vanilla-testvector van AWS', function (): void {
    $signer = new SignatureV4('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service');

    $headers = $signer->sign('GET', 'https://example.amazonaws.com/', [
        'Host' => 'example.amazonaws.com',
        'X-Amz-Date' => '20150830T123600Z',
    ], hash('sha256', ''), new DateTimeImmutable('2015-08-30 12:36:00', new DateTimeZone('UTC')));

    expect($headers['Authorization'])->toBe('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31');
});

it('ondertekent het S3-voorbeeld GET object met range-header', function (): void {
    $signer = new SignatureV4('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    $empty = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    $headers = $signer->sign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', [
        'Range' => 'bytes=0-9',
        'x-amz-content-sha256' => $empty,
    ], $empty, new DateTimeImmutable('2013-05-24 00:00:00', new DateTimeZone('UTC')));

    expect($headers['Authorization'])->toBe('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41')
        ->and($headers['Host'])->toBe('examplebucket.s3.amazonaws.com')
        ->and($headers['X-Amz-Date'])->toBe('20130524T000000Z');
});

it('sorteert de query volgens het S3-voorbeeld ListObjects', function (): void {
    $signer = new SignatureV4('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    $empty = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    $headers = $signer->sign('GET', 'https://examplebucket.s3.amazonaws.com/?prefix=J&max-keys=2', [
        'x-amz-content-sha256' => $empty,
    ], $empty, new DateTimeImmutable('2013-05-24 00:00:00', new DateTimeZone('UTC')));

    expect($headers['Authorization'])->toEndWith('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7');
});

it('neemt een afwijkende poort mee in de host', function (): void {
    $signer = new SignatureV4('id', 'secret', 'us-east-1');

    expect($signer->sign('GET', 'http://localhost:9000/bucket', [], hash('sha256', ''), new DateTimeImmutable)['Host'])->toBe('localhost:9000')
        ->and($signer->sign('GET', 'https://s3.example.com:443/bucket', [], hash('sha256', ''), new DateTimeImmutable)['Host'])->toBe('s3.example.com');
});
