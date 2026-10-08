<?php

namespace App\Exceptions;

use RuntimeException;

class WpOpenException extends RuntimeException
{
    /**
     * De laatste regel met ✗ is de echte melding van het script; de rest is ruis voor de gebruiker.
     */
    public static function fromOutput(string $output, int $exitCode): self
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
        $errors = array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, '✗')));
        $message = $errors !== [] ? end($errors) : (end($lines) ?: __('wpopen stopte met code :code', ['code' => $exitCode]));

        return new self(ltrim($message, '✗ '));
    }
}
