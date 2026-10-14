<?php

/*
 * The reverse proxies in front of the application (Nginx, Cloudflare). Behind one, every request arrives from the
 * proxy's address: unless it is trusted here, rate limits, the sign-in lockout, the audit log and signatures would all
 * see one address for everybody. TRUSTED_PROXIES is a comma-separated list of addresses or ranges, or * when the
 * server can only be reached through the proxy. Empty = no proxy is trusted (direct connections).
 */
return [
    'proxies' => env('TRUSTED_PROXIES') ? array_map('trim', explode(',', (string) env('TRUSTED_PROXIES'))) : null,
];
