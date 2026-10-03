<?php

namespace App\Services;

/**
 * Classifies mail hosting from public MX/SPF only (no mailbox login).
 */
class MailProviderDetector
{
    /**
     * @param  array<string, mixed>  $dns
     * @return array{provider: string, status: string, evidence: array<int, string>, mx: array<int, mixed>}
     */
    public function detect(array $dns): array
    {
        $mxHosts = array_map(
            fn ($row) => strtolower((string) ($row['host'] ?? '')),
            $dns['mx'] ?? []
        );
        $txt = array_map('strtolower', $dns['txt'] ?? []);
        $spf = strtolower((string) ($dns['spf'] ?? ''));
        $evidence = [];

        // MX is the primary signal; SPF is a supporting indicator when MX is missing or generic.
        $googleMx = $this->matchesAny($mxHosts, [
            'google.com',
            'googlemail.com',
        ]);
        $microsoftMx = $this->matchesAny($mxHosts, [
            'mail.protection.outlook.com',
            'protection.outlook.com',
        ]);

        $googleSpf = str_contains($spf, '_spf.google.com')
            || $this->txtContains($txt, '_spf.google.com');
        $microsoftSpf = str_contains($spf, 'include:spf.protection.outlook.com')
            || $this->txtContains($txt, 'include:spf.protection.outlook.com');

        if ($googleMx) {
            $evidence[] = 'MX records point to Google (aspmx/googlemail).';
        }
        if ($googleSpf) {
            $evidence[] = 'SPF includes _spf.google.com.';
        }
        if ($microsoftMx) {
            $evidence[] = 'MX records point to Microsoft 365 (*.mail.protection.outlook.com).';
        }
        if ($microsoftSpf) {
            $evidence[] = 'SPF includes spf.protection.outlook.com.';
        }

        if ($googleMx || ($googleSpf && ! $microsoftMx)) {
            $provider = 'Google Workspace';
        } elseif ($microsoftMx || $microsoftSpf) {
            $provider = 'Microsoft 365';
        } elseif ($mxHosts !== []) {
            $provider = 'Other';
            $evidence[] = 'MX records exist but do not match Google Workspace or Microsoft 365.';
        } else {
            $provider = 'Not Detected';
            $evidence[] = 'No MX records were found, so no mail provider could be detected.';
        }

        // Both Google and Microsoft MX on the same domain is treated as Other, not a guess.
        if ($googleMx && $microsoftMx) {
            $provider = 'Other';
            $evidence[] = 'Conflicting Google and Microsoft MX records were found.';
        }

        $status = $provider === 'Not Detected' ? 'not_detected' : 'detected';

        return [
            'provider' => $provider,
            'status' => $status,
            'evidence' => $evidence,
            'mx' => $dns['mx'] ?? [],
        ];
    }

    /**
     * @param  array<int, string>  $hosts
     * @param  array<int, string>  $needles
     */
    private function matchesAny(array $hosts, array $needles): bool
    {
        foreach ($hosts as $host) {
            foreach ($needles as $needle) {
                if ($host === $needle || str_ends_with($host, '.'.$needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $txt
     */
    private function txtContains(array $txt, string $needle): bool
    {
        foreach ($txt as $record) {
            if (str_contains($record, $needle)) {
                return true;
            }
        }

        return false;
    }
}
