<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Support\Health\OperatorAlert;

/**
 * Check that the external links we publish still work.
 *
 * WHY: the marketplace and training catalogues are CURATED -- vetted by us and
 * shown under our brand. That is the whole argument for curation over an open
 * listing. A dead link is therefore exactly the failure curation exists to
 * prevent, and external URLs decay quietly: courses are retired, companies move
 * their sites, pages are reorganised.
 *
 * Nobody notices until a customer clicks. This notices first.
 *
 * DOES NOT AUTOMATICALLY UNPUBLISH ANYTHING. A site can be briefly down, behind
 * a WAF that dislikes automated requests, or simply slow -- and quietly hiding
 * a provider's listing because their server hiccupped would be worse than the
 * dead link. The operator is told; the operator decides.
 */
#[Signature('obaits:check-links {--verbose-ok : list working links too}')]
#[Description('Verify published training and provider links still resolve')]
class CheckExternalLinks extends Command
{
    public function handle(): int
    {
        $problems = [];

        // --- Trainings --------------------------------------------------
        $trainings = DB::table('trainings')
            ->where('status', 'published')->whereNotNull('url')
            ->get(['id', 'title', 'url']);

        foreach ($trainings as $t) {
            $result = $this->check($t->url);
            $this->report('training', $t->title, $t->url, $result, $problems);
        }

        // --- Provider websites ------------------------------------------
        $providers = DB::table('marketplace_providers')
            ->where('lifecycle', 'active')->whereNotNull('website')
            ->get(['id', 'display_name', 'website']);

        foreach ($providers as $p) {
            // Skip the deliberately unroutable example providers.
            if (str_contains($p->website, 'example.invalid')) { continue; }

            $result = $this->check($p->website);
            $this->report('provider', $p->display_name, $p->website, $result, $problems);
        }

        $this->line('');
        $this->info(count($problems) . ' problem(s) from ' . ($trainings->count() + $providers->count()) . ' link(s).');

        if ($problems) {
            OperatorAlert::send(count($problems) . ' published link(s) are not working', $problems);
        }

        return self::SUCCESS;
    }

    /** @return array{0: bool, 1: string} */
    private function check(string $url): array
    {
        try {
            // HEAD first -- cheaper, and enough for most sites. Some reject it,
            // so fall back to GET rather than reporting a false failure.
            $res = Http::timeout(12)->withoutVerifying()
                ->withHeaders(['User-Agent' => 'Obaits link checker (+https://www.obaits.com)'])
                ->head($url);

            if ($res->status() === 405 || $res->status() === 501) {
                $res = Http::timeout(12)->withoutVerifying()
                    ->withHeaders(['User-Agent' => 'Obaits link checker (+https://www.obaits.com)'])
                    ->get($url);
            }

            $code = $res->status();

            // 2xx and 3xx are fine. 401/403 usually means a WAF or a login
            // wall, not a dead page -- worth noting, not worth alarming about.
            if ($code >= 200 && $code < 400) { return [true, (string) $code]; }
            if (in_array($code, [401, 403], true)) { return [true, $code . ' (protected, assumed live)']; }

            return [false, 'HTTP ' . $code];
        } catch (\Throwable $e) {
            return [false, 'unreachable: ' . \Illuminate\Support\Str::limit($e->getMessage(), 80)];
        }
    }

    private function report(string $kind, string $name, string $url, array $result, array &$problems): void
    {
        [$ok, $detail] = $result;

        if ($ok) {
            if ($this->option('verbose-ok')) {
                $this->line("  [ok] {$kind}: {$name} ({$detail})");
            }
            return;
        }

        $this->error("  [dead] {$kind}: {$name} — {$detail}");
        $this->line("         {$url}");

        $problems[] = [
            'level' => 'warning',
            'area' => 'catalogue',
            'message' => ucfirst($kind) . " \"{$name}\" link is not working ({$detail}): {$url}",
        ];
    }
}
