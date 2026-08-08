<?php

namespace App\Integrations;

use RuntimeException;

/**
 * A connector failure. The message is written for a human reading the
 * sync log, and never carries a credential — `redactMessage()` is applied
 * to anything that came back from a remote system or a driver, because a
 * PDO exception happily prints the DSN it just failed to open (R9).
 */
class ConnectorException extends RuntimeException
{
    public static function from(\Throwable $previous, string $context = 'Extraction failed'): self
    {
        return new self($context.': '.self::redactMessage($previous->getMessage()), 0, $previous);
    }

    /** Strip anything shaped like a secret out of a third-party message. */
    public static function redactMessage(string $message): string
    {
        $patterns = [
            // user:password@host
            '/\/\/[^\/\s:@]+:[^\/\s@]+@/i' => '//[redacted]@',
            // key=value pairs naming a secret
            '/\b(password|passwd|pwd|secret|token|api[_-]?key|client[_-]?secret|authorization|bearer)\b\s*[:=]\s*\S+/i' => '$1=[redacted]',
            // bare JWT-ish blobs
            '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]+\.?[A-Za-z0-9_\-]*/' => '[redacted-token]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $message = (string) preg_replace($pattern, $replacement, $message);
        }

        return mb_substr($message, 0, 500);
    }
}
