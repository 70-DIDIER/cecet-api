<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

class InvalidWebhookSignature extends RuntimeException {}
