<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Marketplace catalogue seed.
 *
 * A SEEDER, NOT A MIGRATION, deliberately. A production instance must not
 * acquire example providers by deploying. Run explicitly:
 *
 *     php artisan db:seed --class=MarketplaceDemoSeeder
 *
 * ON PROVIDER STATUS AND HONESTY. Provider status carries a real legal
 * obligation: a provider must never be presented as official or authorized
 * unless the manufacturer has actually granted that authorization. Technical
 * integration is not endorsement.
 *
 * So Wazuh, Greenbone and the rest are NOT seeded as providers. They have
 * granted nothing, and a listing saying otherwise would misrepresent a
 * relationship that does not exist -- even as demo data, even internally.
 *
 * Obaits IS seeded as an official upstream provider, but only for the 'obaits'
 * technology -- its own platform, which it can honestly claim to be official
 * for and can actually deliver services on.
 *
 * The remaining providers are transparently fictional and marked verified or
 * community. Their SERVICES reference real technologies, which is fine: offering
 * "Wazuh architecture review" claims expertise, not Wazuh's endorsement.
 */
class MarketplaceDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent: a seeder that explodes on a second run is unhelpful,
        // especially a development one where re-seeding is normal. Clears its
        // OWN example data first, keyed on the slugs it creates, so it never
        // touches providers added by anyone else.
        $slugs = ['obaits', 'sentinel-security-example', 'atlas-consulting-example'];
        $ids = DB::table('marketplace_providers')->whereIn('slug', $slugs)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('marketplace_services')->whereIn('provider_id', $ids)->delete();
            DB::table('marketplace_providers')->whereIn('id', $ids)->delete();
            $this->command->info('Cleared ' . $ids->count() . ' existing seeded provider(s).');
        }

        $obaits = $this->provider([
            'legal_name' => 'Obaits SARL',
            'display_name' => 'Obaits',
            'slug' => 'obaits',
            'status' => 'official_upstream',   // honest: this is our own platform
            'description' => 'The team behind the Obaits platform. Deployment, migration, tuning and managed operation of Obaits itself, delivered by the people who build it.',
            'contact_email' => 'services@obaits.com',
            'website' => 'https://obaits.com',
            'technologies' => ['obaits', 'wazuh', 'greenbone', 'fleet', 'zabbix'],
            'regions' => ['CM', 'CI', 'SN', 'GA', 'CD'],
            'languages' => ['en', 'fr'],
            'delivery_models' => ['remote', 'onsite', 'hybrid'],
            'response_time' => 'Within 1 business day',
        ]);

        $sentinel = $this->provider([
            'legal_name' => 'Example Sentinel Security Ltd',
            'display_name' => 'Sentinel Security (example)',
            'slug' => 'sentinel-security-example',
            'status' => 'verified',
            'description' => 'EXAMPLE LISTING. A security consultancy specialising in detection engineering and SIEM tuning.',
            'contact_email' => 'hello@example.invalid',
            'technologies' => ['wazuh', 'zabbix'],
            'regions' => ['CM', 'NG', 'GH'],
            'languages' => ['en'],
            'delivery_models' => ['remote'],
            'response_time' => 'Within 2 business days',
        ]);

        $atlas = $this->provider([
            'legal_name' => 'Example Atlas Consulting',
            'display_name' => 'Atlas Consulting (example)',
            'slug' => 'atlas-consulting-example',
            'status' => 'community',
            'description' => 'EXAMPLE LISTING. Independent GRC and vulnerability management consultancy.',
            'contact_email' => 'contact@example.invalid',
            'technologies' => ['greenbone', 'ciso'],
            'regions' => ['CM', 'FR'],
            'languages' => ['fr', 'en'],
            'delivery_models' => ['remote', 'onsite'],
            'response_time' => 'Within 3 business days',
        ]);

        $services = [
            [$obaits, 'implementation', 'obaits', 'Obaits deployment and onboarding',
             'End-to-end deployment of the Obaits platform for your organisation: enrolment of devices, tenant configuration, policy baselines, VPN setup and handover training for your team.',
             'From quote', 'Within 1 business day', ['remote','onsite','hybrid'], ['CM','CI','SN','GA','CD']],

            [$obaits, 'managed_services', 'obaits', 'Managed Obaits operation',
             'We run your Obaits platform for you: monitoring alerts, triaging findings, keeping policies current and reporting monthly on your security posture.',
             'Monthly subscription', 'Within 4 hours', ['remote'], ['CM','CI','SN','GA','CD']],

            [$obaits, 'implementation', 'wazuh', 'Wazuh deployment and tuning',
             'Deployment of Wazuh within your Obaits environment, including custom rules and decoders for your applications, and tuning to reduce alert noise.',
             'From quote', 'Within 1 business day', ['remote','onsite'], ['CM','CI','SN','GA','CD']],

            [$sentinel, 'consulting', 'wazuh', 'Detection engineering review',
             'EXAMPLE LISTING. Review of your detection coverage against your threat model, with a prioritised set of new rules and tuning recommendations.',
             null, 'Within 2 business days', ['remote'], ['CM','NG','GH']],

            [$sentinel, 'technical_support', 'zabbix', 'Zabbix architecture and optimization',
             'EXAMPLE LISTING. Assessment of an existing Zabbix deployment: template design, proxy topology, database sizing and alert routing.',
             null, 'Within 2 business days', ['remote'], ['CM','NG','GH']],

            [$atlas, 'consulting', 'ciso', 'ISO 27001 readiness assessment',
             'EXAMPLE LISTING. Gap assessment against ISO 27001 controls with a prioritised remediation plan and evidence requirements.',
             'From quote', 'Within 3 business days', ['remote','onsite'], ['CM','FR']],

            [$atlas, 'professional_services', 'greenbone', 'Vulnerability management programme design',
             'EXAMPLE LISTING. Design of a vulnerability management programme: scan scheduling, risk acceptance criteria, remediation SLAs and reporting.',
             null, 'Within 3 business days', ['remote'], ['CM','FR']],
        ];

        foreach ($services as [$pid, $cat, $tech, $title, $desc, $pricing, $rt, $dm, $regions]) {
            DB::table('marketplace_services')->insert([
                'id' => (string) Str::uuid(),
                'provider_id' => $pid,
                'category' => $cat,
                'technology' => $tech,
                'title' => $title,
                'description' => $desc,
                'pricing' => $pricing,
                'response_time' => $rt,
                'delivery_models' => json_encode($dm),
                'regions' => json_encode($regions),
                'status' => 'published',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->command->info('Seeded 3 providers and ' . count($services) . ' services.');
    }

    private function provider(array $a): string
    {
        $id = (string) Str::uuid();
        DB::table('marketplace_providers')->insert([
            'id' => $id,
            'legal_name' => $a['legal_name'],
            'display_name' => $a['display_name'],
            'slug' => $a['slug'],
            'status' => $a['status'],
            'lifecycle' => 'active',
            'description' => $a['description'],
            'website' => $a['website'] ?? null,
            'contact_email' => $a['contact_email'],
            'technologies' => json_encode($a['technologies']),
            'regions' => json_encode($a['regions']),
            'languages' => json_encode($a['languages']),
            'delivery_models' => json_encode($a['delivery_models']),
            'response_time' => $a['response_time'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
