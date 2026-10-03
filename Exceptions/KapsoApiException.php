<?php

namespace Modules\KapsoWhatsApp\Exceptions;

/**
 * Every message on this exception is written to be shown to an admin as-is.
 * getHttpStatus() is 0 when no HTTP response was ever received (DNS failure,
 * refused connection, timeout) so callers can tell "Kapso said no" apart from
 * "we never reached Kapso".
 */
class KapsoApiException extends \Exception
{
    protected $httpStatus;

    public function __construct($message, $httpStatus = 0, \Exception $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->httpStatus = (int) $httpStatus;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    /**
     * Worth trying again later: Kapso was never reached (DNS, refused
     * connection, timeout -- KapsoClient always chains the transport
     * exception as $previous), or it answered 408/429/5xx. Everything else
     * fails the same way on every retry: a 4xx rejection, or a status-0
     * failure raised before any request was made (no API key configured).
     */
    public function isTransient()
    {
        if ($this->httpStatus === 0) {
            return $this->getPrevious() !== null;
        }

        return in_array($this->httpStatus, [408, 429], true) || $this->httpStatus >= 500;
    }
}
