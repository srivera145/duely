<?php

namespace Keel\App\Services;

use RuntimeException;

/**
 * Raised when encryption or decryption cannot be completed. A failed GCM tag
 * check surfaces as this rather than a null credential, so a key mistake is
 * loud at the point of use instead of silently sending unauthenticated mail.
 */
class CryptoException extends RuntimeException
{
}
