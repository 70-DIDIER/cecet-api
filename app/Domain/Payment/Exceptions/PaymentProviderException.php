<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * A provider call failed (network, authentication, rejected request). Never exposed to API clients as is.
 */
class PaymentProviderException extends RuntimeException {}
