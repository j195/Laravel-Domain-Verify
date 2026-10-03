<?php

return [
    // Seconds PHP waits on each DNS query, then how many extra attempts after a timeout.
    'dns_timeout' => (int) env('MAILIN_DNS_TIMEOUT', 3),
    'dns_retries' => (int) env('MAILIN_DNS_RETRIES', 2),
    // UI tick already processes one domain at a time; keep this at 1.
    'bulk_concurrency' => (int) env('MAILIN_BULK_CONCURRENCY', 1),
    'bulk_max_items' => (int) env('MAILIN_BULK_MAX_ITEMS', 5000),
    'rate_limit_per_minute' => (int) env('MAILIN_RATE_LIMIT', 60),

    'dkim_selectors' => [
        'google',
        'selector1',
        'selector2',
        'default',
        'k1',
    ],

    // IP-based RBLs (query reversed-IP.list). Domain-based lists are below.
    'dnsbls' => [
        'zen.spamhaus.org' => 'Spamhaus ZEN',
        'bl.spamcop.net' => 'SpamCop',
        'b.barracudacentral.org' => 'Barracuda',
        'dnsbl.sorbs.net' => 'SORBS',
        'cbl.abuseat.org' => 'CBL Abuseat',
        'psbl.surriel.com' => 'PSBL',
        'dnsbl-1.uceprotect.net' => 'UCEPROTECT L1',
        'ix.dnsbl.manitu.net' => 'Manitu ix',
        'truncate.gbudb.net' => 'GBUdb Truncate',
        'all.s5h.net' => 'S5H',
        'dnsbl.dronebl.org' => 'DroneBL',
        'rbl.interserver.net' => 'InterServer',
    ],

    // Domain/URI blocklists: query example.com.dbl.spamhaus.org
    'domain_dnsbls' => [
        'dbl.spamhaus.org' => 'Spamhaus DBL',
        'multi.surbl.org' => 'SURBL',
        'black.uribl.com' => 'URIBL Black',
    ],
];
