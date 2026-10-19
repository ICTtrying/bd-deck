<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Fout bij cloud-back-ups; de melding is altijd geschikt om aan de gebruiker te tonen.
 */
class OffsiteException extends RuntimeException
{
    public const string UNREACHABLE = 'unreachable';

    public const string WRONG_KEY = 'wrong_key';

    public const string NO_ACCESS = 'no_access';

    public const string NO_BUCKET = 'no_bucket';

    public const string WRONG_REGION = 'wrong_region';

    public const string WRONG_PASSPHRASE = 'wrong_passphrase';

    public const string DAMAGED = 'damaged';

    public const string TEMPORARY = 'temporary';

    public const string OTHER = 'other';

    public function __construct(string $message, public readonly string $reason = self::OTHER, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Netwerk- en serverstoringen gaan vaak vanzelf over; een foute sleutel of bucket niet.
     */
    public function isTemporary(): bool
    {
        return in_array($this->reason, [self::UNREACHABLE, self::TEMPORARY], true);
    }

    public static function unreachable(string $endpoint, ?Throwable $previous = null): self
    {
        return new self(__('Kan :host niet bereiken. Controleer het endpoint en je internetverbinding.', [
            'host' => parse_url($endpoint, PHP_URL_HOST) ?: $endpoint,
        ]), self::UNREACHABLE, $previous);
    }

    public static function wrongPassphrase(): self
    {
        return new self(__('Deze back-up is versleuteld met een ander wachtwoord. Zet in Instellingen het wachtwoord terug waarmee hij gemaakt is.'), self::WRONG_PASSPHRASE);
    }

    public static function damaged(): self
    {
        return new self(__('De back-up is beschadigd of onvolledig en kan niet ontsleuteld worden.'), self::DAMAGED);
    }

    /**
     * Vertaalt de foutcodes van S3 (en de varianten van B2, R2 en Wasabi) naar iets wat een webdeveloper snapt.
     */
    public static function fromResponse(Response $response, string $bucket): self
    {
        $code = preg_match('#<Code>([^<]+)</Code>#', $response->body(), $matches) === 1 ? $matches[1] : '';
        $status = $response->status();

        return match (true) {
            in_array($code, ['InvalidAccessKeyId', 'SignatureDoesNotMatch', 'InvalidToken', 'ExpiredToken', 'InvalidSecurity', 'Unauthorized'], true), $status === 401 => new self(
                __('De sleutel klopt niet. Controleer de access key ID en de geheime sleutel.'),
                self::WRONG_KEY,
            ),
            $code === 'NoSuchBucket' => new self(
                __('De bucket :bucket bestaat niet. Controleer de naam of maak hem eerst aan bij je opslagdienst.', ['bucket' => $bucket]),
                self::NO_BUCKET,
            ),
            in_array($code, ['PermanentRedirect', 'AuthorizationHeaderMalformed', 'IllegalLocationConstraintException', 'InvalidRegion'], true), in_array($status, [301, 307], true) => new self(
                __('De bucket :bucket staat in een andere regio. Controleer de regio en het endpoint.', ['bucket' => $bucket]),
                self::WRONG_REGION,
            ),
            in_array($code, ['AccessDenied', 'AllAccessDisabled', 'AccountProblem'], true), $status === 403 => new self(
                __('Deze sleutel heeft geen toegang tot bucket :bucket. Geef de sleutel lees-, schrijf- en verwijderrechten op deze bucket.', ['bucket' => $bucket]),
                self::NO_ACCESS,
            ),
            default => new self(
                __('De opslag gaf een onverwachte fout (:status :code).', ['status' => $status, 'code' => $code ?: $response->reason()]),
                $status >= 500 || $status === 429 ? self::TEMPORARY : self::OTHER,
            ),
        };
    }
}
