<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by the residency guard when an operation would store or move data
 * outside the declared residency country (16.2). This is a block, not a
 * warning — callers must not catch-and-continue.
 */
class ResidencyViolationException extends RuntimeException {}
