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
}
