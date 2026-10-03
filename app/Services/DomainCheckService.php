<?php

namespace App\Services;

use App\Support\DomainNormalizer;
use InvalidArgumentException;

/**
 * Orchestrates a single domain check: normalize → DNS → blacklist or provider label.
 */
class DomainCheckService
{
    public function __construct(
        private DnsLookupService $dns,
        private BlacklistService $blacklist,
        private MailProviderDetector $providers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function blacklistAndDns(string $input, ?string $dkimSelector = null, bool $includeAll = false): array
    {
        $domain = DomainNormalizer::fromInput($input);
        $dns = $this->dns->lookup($domain, $dkimSelector, $includeAll);
        $ipv4 = $dns['a'] ?? [];

        // A raw IPv4 has no A-record lookup; still check that address against DNSBLs.
        if ($ipv4 === [] && filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipv4 = [$domain];
            $dns['a'] = $ipv4;
        }

        $blacklist = $this->blacklist->check($domain, $ipv4);

        return [
            'input' => $input,
            'domain' => $domain,
            'blacklist' => [
                'status' => $blacklist['status'] === 'listed' ? 'Listed' : 'Clean',
                'lists' => $blacklist['listed_on'],
                'checked' => $blacklist['checked'],
            ],
            'mx' => [
                'status' => ($dns['mx'] ?? []) === [] ? 'Missing' : 'Present',
                'records' => $dns['mx'] ?? [],
            ],
            'spf' => [
                'status' => $dns['spf'] ? 'Present' : 'Missing',
                'value' => $dns['spf'],
            ],
            'dmarc' => [
                'status' => $dns['dmarc'] ? 'Present' : 'Missing',
                'value' => $dns['dmarc'],
            ],
            'dkim' => $dns['dkim'],
            'nameservers' => $dns['ns'] ?? [],
            'a' => $dns['a'] ?? [],
            'aaaa' => $dns['aaaa'] ?? [],
            'cname' => $dns['cname'] ?? [],
            'ptr' => $dns['ptr'] ?? [],
            'txt' => $dns['txt'] ?? [],
            'all_records' => $dns['all_records'] ?? [],
            'errors' => array_values(array_unique(array_merge($dns['errors'] ?? [], $blacklist['errors'] ?? []))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function provider(string $input): array
    {
        $domain = DomainNormalizer::fromInput($input);
        // Provider detection only needs MX/TXT/SPF; skip DKIM selector sweeps to keep bulk jobs faster.
        $dns = $this->dns->lookup($domain, includeDkim: false);
        $detection = $this->providers->detect($dns);

        return [
            'input' => $input,
            'domain' => $domain,
            'provider' => $detection['provider'],
            'status' => $detection['status'] === 'detected' ? 'Active/Detected' : 'Not Detected',
            'mx' => $detection['mx'],
            'evidence' => $detection['evidence'],
            'errors' => $dns['errors'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $type, string $input, ?string $dkimSelector = null, bool $includeAll = false): array
    {
        return match ($type) {
            'blacklist' => $this->blacklistAndDns($input, $dkimSelector, $includeAll),
            'provider' => $this->provider($input),
            default => throw new InvalidArgumentException('Unknown check type.'),
        };
    }
}
