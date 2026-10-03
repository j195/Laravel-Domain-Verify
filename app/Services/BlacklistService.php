<?php

namespace App\Services;

/**
 * DNSBL/RBL checks: listed if the query name has an A record (usually 127.0.0.x).
 * IP lists use the reversed octet form; domain lists query domain.list-zone.
 */
class BlacklistService
{
    /**
     * @param  array<int, string>  $ipv4
     * @return array{status: string, listed_on: array<int, array<string, mixed>>, checked: int, errors: array<int, string>}
     */
    public function check(string $domain, array $ipv4): array
    {
        $zones = config('mailin.dnsbls', []);
        $timeout = min(2, (int) config('mailin.dns_timeout', 3));
        $listed = [];
        $errors = [];
        $checked = 0;

        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $timeout);

        try {
            $targets = array_slice(array_values(array_unique(array_filter($ipv4))), 0, 2);

            if ($targets === []) {
                $errors[] = 'No A records available for blacklist IP checks.';
            }

            foreach ($targets as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    continue;
                }

                // 1.2.3.4 on zen.spamhaus.org is queried as 4.3.2.1.zen.spamhaus.org
                $reversed = implode('.', array_reverse(explode('.', $ip)));

                foreach ($zones as $zone => $label) {
                    $hit = $this->queryList($reversed.'.'.$zone, $label, $ip, $errors);
                    $checked++;
                    if ($hit) {
                        $listed[] = $hit;
                    }
                }
            }

            if (! filter_var($domain, FILTER_VALIDATE_IP)) {
                foreach (config('mailin.domain_dnsbls', []) as $zone => $label) {
                    $hit = $this->queryList($domain.'.'.$zone, $label, $domain, $errors);
                    $checked++;
                    if ($hit) {
                        $listed[] = $hit;
                    }
                }
            }
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }

        return [
            'status' => $listed === [] ? 'clean' : 'listed',
            'listed_on' => $listed,
            'checked' => $checked,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * checkdnsrr true = the list returned an A record (listed). false = not listed or lookup failed.
     *
     * @param  array<int, string>  $errors
     * @return array<string, mixed>|null
     */
    private function queryList(string $query, string $label, string $target, array &$errors): ?array
    {
        try {
            $records = @checkdnsrr($query, 'A');
            if ($records === false) {
                return null;
            }

            $reason = @dns_get_record($query, DNS_TXT);

            return [
                'target' => $target,
                'zone' => explode('.', $query, 2)[1] ?? $query,
                'name' => $label,
                'reason' => $reason[0]['txt'] ?? ($reason[0]['entries'][0] ?? null),
            ];
        } catch (\Throwable $e) {
            $errors[] = "{$label}: {$e->getMessage()}";

            return null;
        }
    }
}
