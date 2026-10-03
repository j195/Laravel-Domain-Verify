<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Turns user input (domain, URL, email, or IP) into a lowercase host we can query in DNS.
 */
class DomainNormalizer
{
    public static function fromInput(string $value): string
    {
        $original = trim($value, " \t\n\r\0\x0B\"'");
        $original = preg_replace('/^mailto:/i', '', $original) ?? $original;
        $value = strtolower($original);
        $value = preg_replace('/^https?:\/\//', '', $value) ?? $value;
        $value = explode('/', $value)[0];
        $value = explode('?', $value)[0];
        $value = explode('#', $value)[0];

        // Emails: keep the domain after @. URLs with user:pass@host are not treated as emails.
        $looksLikeEmail = str_contains($original, '@') && ! str_contains($original, '://');

        if (str_contains($value, '@')) {
            $local = strstr($value, '@', true);
            $value = substr(strrchr($value, '@') ?: '', 1);

            if ($looksLikeEmail) {
                $email = strtolower(trim($original));
                $email = preg_replace('/^mailto:/i', '', $email) ?? $email;

                if ($local === false || $local === '' || $value === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter a valid email address, or a domain such as example.com.');
                }
            }
        }

        $value = self::stripPort($value);
        $value = trim($value, ". \t\n\r\0\x0B");

        if ($value === '') {
            throw new InvalidArgumentException('Enter a valid domain or email address.');
        }

        // Punycode IDN hosts (e.g. münchen.de) so DNS lookups use ASCII.
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $value = strtolower($ascii);
            }
        }

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }

        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/i', $value)) {
            throw new InvalidArgumentException('Enter a valid domain or email address.');
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        try {
            self::fromInput($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Drop :443-style ports. Bracketed IPv6 ([::1]:80) is reduced to the address only.
     */
    private static function stripPort(string $host): string
    {
        if (str_contains($host, ']') && str_starts_with($host, '[')) {
            $end = strrpos($host, ']');

            return $end === false ? $host : substr($host, 1, $end - 1);
        }

        if (preg_match('/^(.+):(\d{1,5})$/', $host, $matches) === 1) {
            return $matches[1];
        }

        return $host;
    }
}
