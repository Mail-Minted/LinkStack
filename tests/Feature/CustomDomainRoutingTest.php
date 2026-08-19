<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustHosts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Customer custom-domain routing (routes/home.php + TrustHosts).
 *
 * Found in production with the first live bio domain: TrustHosts only
 * trusted APP_URL's subdomains, so every request that arrived with a
 * customer's Host got 404'd before routing — the custom-domain mapping
 * never ran. Laravel bypasses TrustHosts in the testing environment,
 * so the middleware's host patterns are asserted directly here; the
 * routing tests cover the home.php mapping (including the www variant,
 * which customer zones always ship as a CNAME).
 */
class CustomDomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function makeBioUser(string $domain): User
    {
        $user = $this->makeUser([
            'name' => 'BioOwner ' . str_replace('.', '-', $domain),
            'littlelink_name' => 'bio-' . str_replace('.', '-', $domain),
            'custom_domain' => $domain,
            'block' => 'no',
        ]);

        // These tests are about host -> page routing, so the page needs
        // content: an untouched page serves the holding page instead
        // (covered by ComingSoonPageTest).
        $this->makeBlock($user);

        return $user;
    }

    public function test_trusthosts_patterns_include_custom_domains_and_www(): void
    {
        $this->makeBioUser('customerbio.example');
        Cache::forget('trusted_custom_domains');

        $patterns = (new TrustHosts(app()))->hosts();

        $matches = function (string $host) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if ($pattern && preg_match('{' . $pattern . '}i', $host)) {
                    return true;
                }
            }
            return false;
        };

        $this->assertTrue($matches('customerbio.example'), 'apex custom domain must be trusted');
        $this->assertTrue($matches('www.customerbio.example'), 'www variant must be trusted');
        $this->assertFalse($matches('evil.example'), 'unrelated hosts must stay untrusted');
        $this->assertFalse(
            $matches('customerbio.example.evil.example'),
            'pattern must be anchored — suffix spoofing must stay untrusted',
        );
    }

    public function test_apex_host_maps_root_to_bio_page(): void
    {
        $user = $this->makeBioUser('apexmap.example');

        $response = $this->get('http://apexmap.example/');

        $response->assertOk();
        $response->assertSee($user->name);
    }

    public function test_www_host_maps_root_to_same_bio_page(): void
    {
        $user = $this->makeBioUser('wwwmap.example');

        $response = $this->get('http://www.wwwmap.example/');

        $response->assertOk();
        $response->assertSee($user->name);
    }
}
