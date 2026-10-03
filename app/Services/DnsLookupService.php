<?php

namespace App\Services;

/**
 * Live DNS lookups via PHP's resolver (dns_get_record).
 * Timeouts and retries come from config/mailin.php so a slow nameserver does not hang the request forever.
 */
class DnsLookupService
{
    public function lookup(string $domain, ?string $dkimSelector = null, bool $includeAll = false, bool $includeDkim = true): array
    {
        $errors = [];
        $timeout = (int) config('mailin.dns_timeout', 3);
        $retries = (int) config('mailin.dns_retries', 2);

        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $timeout);

        try {
            $a = $this->records($domain, DNS_A, $retries, $errors);
            $aaaa = $this->records($domain, DNS_AAAA, $retries, $errors);
            $mx = $this->records($domain, DNS_MX, $retries, $errors);
            $ns = $this->records($domain, DNS_NS, $retries, $errors);
            $cname = $this->records($domain, DNS_CNAME, $retries, $errors);
            $txt = $this->records($domain, DNS_TXT, $retries, $errors);
            $soa = $includeAll ? $this->records($domain, DNS_SOA, $retries, $errors) : [];
            $caa = $includeAll ? $this->records($domain, DNS_CAA, $retries, $errors) : [];

            $spf = $this->extractSpf($txt);
            // DMARC always lives on the _dmarc subdomain, not the apex TXT set.
            $dmarc = $this->lookupDmarc($domain, $retries, $errors);
            $dkim = $includeDkim
                ? $this->lookupDkim($domain, $dkimSelector, $retries, $errors)
                : ['checked_selectors' => [], 'records' => [], 'status' => 'skipped'];
            $ptr = $this->lookupPtr($a, $retries, $errors);

            usort($mx, fn ($left, $right) => ($left['pri'] ?? 0) <=> ($right['pri'] ?? 0));

            return [
                'domain' => $domain,
                'a' => array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? null, $a))),
                'aaaa' => array_values(array_filter(array_map(fn ($r) => $r['ipv6'] ?? null, $aaaa))),
                'mx' => array_map(fn ($r) => [
                    'host' => rtrim((string) ($r['target'] ?? ''), '.'),
                    'priority' => $r['pri'] ?? null,
                ], $mx),
                'ns' => array_values(array_filter(array_map(fn ($r) => rtrim((string) ($r['target'] ?? ''), '.'), $ns))),
                'cname' => array_values(array_filter(array_map(fn ($r) => rtrim((string) ($r['target'] ?? ''), '.'), $cname))),
                'txt' => array_values(array_filter(array_map(fn ($r) => implode('', $r['entries'] ?? [$r['txt'] ?? '']), $txt))),
                'spf' => $spf,
                'dmarc' => $dmarc,
                'dkim' => $dkim,
                'ptr' => $ptr,
                'soa' => $soa,
                'caa' => $caa,
                'all_records' => $includeAll ? $this->records($domain, DNS_ALL, $retries, $errors) : [],
                'errors' => array_values(array_unique($errors)),
            ];
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }
    }

    /**
     * false from dns_get_record is a timeout/resolver failure; [] means the name exists but has no records of that type.
     *
     * @param  array<int, string>  $errors
     * @return array<int, array<string, mixed>>
     */
    private function records(string $host, int $type, int $retries, array &$errors): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $result = @dns_get_record($host, $type);

            if ($result !== false) {
                return $result;
            }

            $lastError = error_get_last()['message'] ?? "DNS lookup failed for {$host}";
            usleep(150000 * $attempt);
        }

        $errors[] = $lastError ?: "DNS timeout for {$host}";

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $txt
     */
    private function extractSpf(array $txt): ?string
    {
        foreach ($txt as $record) {
            $value = implode('', $record['entries'] ?? [$record['txt'] ?? '']);
            if (str_starts_with(strtolower($value), 'v=spf1')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function lookupDmarc(string $domain, int $retries, array &$errors): ?string
    {
        $records = $this->records('_dmarc.'.$domain, DNS_TXT, $retries, $errors);

        foreach ($records as $record) {
            $value = implode('', $record['entries'] ?? [$record['txt'] ?? '']);
            if (str_starts_with(strtolower($value), 'v=dmarc1')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * DKIM is selector-specific: google._domainkey.example.com. Known defaults are tried unless the operator typed one.
     *
     * @param  array<int, string>  $errors
     * @return array<string, mixed>
     */
    private function lookupDkim(string $domain, ?string $selector, int $retries, array &$errors): array
    {
        $selectors = $selector
            ? [trim($selector)]
            : config('mailin.dkim_selectors', ['google', 'selector1', 'selector2']);

        $found = [];

        foreach ($selectors as $name) {
            $host = $name.'._domainkey.'.$domain;
            $records = $this->records($host, DNS_TXT, $retries, $errors);

            foreach ($records as $record) {
                $value = implode('', $record['entries'] ?? [$record['txt'] ?? '']);
                if ($value !== '') {
                    $found[] = [
                        'selector' => $name,
                        'value' => $value,
                    ];
                }
            }

            if ($selector && $found !== []) {
                break;
            }
        }

        return [
            'checked_selectors' => array_values($selectors),
            'records' => $found,
            'status' => $found === [] ? 'not_found' : 'found',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $aRecords
     * @param  array<int, string>  $errors
     * @return array<int, array{ip: string, ptr: string|null}>
     */
    private function lookupPtr(array $aRecords, int $retries, array &$errors): array
    {
        $results = [];

        foreach ($aRecords as $record) {
            $ip = $record['ip'] ?? null;
            if (! $ip) {
                continue;
            }

            $ptr = null;
            for ($attempt = 1; $attempt <= $retries; $attempt++) {
                $hostname = @gethostbyaddr($ip);
                if ($hostname && $hostname !== $ip) {
                    $ptr = $hostname;
                    break;
                }
            }

            $results[] = ['ip' => $ip, 'ptr' => $ptr];
        }

        return $results;
    }
}
