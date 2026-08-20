<?php

namespace App\Services\Messaging\Exceptions;

use RuntimeException;

/**
 * Thrown by OutboundContentGuard when an external-channel payload looks
 * like it carries personal data or case substance. The channel catches it,
 * records a `skipped` message-log row with the reason, and the business
 * operation continues — a blocked reminder must never break the workflow
 * that triggered it.
 */
class BlockedContentException extends RuntimeException {}
