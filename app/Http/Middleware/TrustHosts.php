<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * Beyond APP_URL and its subdomains, every customer bio domain
     * (users.custom_domain) must be trusted or the Host check 404s the
     * request before routes/home.php can map it to the customer's page
     * — TrustHosts runs ahead of routing, so without this the whole
     * custom-domain feature is dead in production. (Laravel bypasses
     * TrustHosts in local/testing environments, which is why the gap
     * never surfaced in dev.) The www variant is trusted alongside each
     * apex because customer zones ship a www CNAME.
     *
     * Cached for 60s so we don't pluck the column on every request;
     * provisioning a new domain becomes routable within a minute.
     *
     * @return array
     */
    public function hosts()
    {
        $hosts = [
            $this->allSubdomainsOfApplicationUrl(),
        ];

        try {
            $customDomains = Cache::remember('trusted_custom_domains', 60, function () {
                if (!Schema::hasColumn('users', 'custom_domain')) {
                    return [];
                }
                return \App\Models\User::whereNotNull('custom_domain')
                    ->pluck('custom_domain')
                    ->all();
            });
        } catch (\Throwable $e) {
            // Pre-migration install or DB unavailable — fall back to
            // trusting only the application host rather than erroring.
            $customDomains = [];
        }

        foreach ($customDomains as $domain) {
            $hosts[] = '^(www\.)?' . preg_quote(strtolower($domain)) . '$';
        }

        return $hosts;
    }
}
