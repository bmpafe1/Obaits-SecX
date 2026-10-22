<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Rotates any tenant's Headscale pre-auth key that is within
 * ROTATE_WITHIN_DAYS of expiring, generating and storing a fresh
 * reusable, 1-year key.
 *
 * DELIBERATE DESIGN: the OLD key is left valid until its own natural
 * expiry rather than immediately revoked. A reusable pre-auth key is
 * embedded in every device's enrollment at the time it joins — revoking
 * it early would not affect already-joined devices (Headscale
 * authenticates by node key thereafter, not the pre-auth key), but WOULD
 * break enrollment for any device mid-install that fetched the old key
 * moments before rotation ran. Overlapping validity avoids that race
 * entirely; the cost is trivial (a stale key sitting unused).
 *
 * MUST run as root — same constraint as ProvisionHeadscaleInstance and
 * SyncHeadscaleNodeCache (reads/writes each tenant's Headscale config and
 * unix socket).
 */
#[Signature('headscale:rotate-preauth-keys {--force : rotate every active instance regardless of expiry}')]
#[Description('Rotate any tenant Headscale pre-auth key nearing expiration')]
class RotateHeadscalePreauthKeys extends Command
{
    private const ROTATE_WITHIN_DAYS = 30;

    public function handle(): int
    {
        if (posix_getuid() !== 0) {
            $this->error('This command must run as root (reads/writes each tenant\'s headscale config).');
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $cutoff = now()->addDays(self::ROTATE_WITHIN_DAYS);

        $instances = DB::table('headscale_instances')
            ->where('status', 'active')
            ->when(!$force, fn ($q) => $q->where('preauth_key_expires_at', '<=', $cutoff))
            ->get();

        if ($instances->isEmpty()) {
            $this->info('No pre-auth keys need rotation.');
            return self::SUCCESS;
        }

        foreach ($instances as $instance) {
            $configPath = "/etc/headscale-{$instance->slug}/config.yaml";

            if (!$instance->headscale_user_id) {
                \App\Support\Health\OperatorAlert::send('Headscale key rotation skipped — no user id on record', [
                    ['level' => 'critical', 'area' => 'headscale',
                     'message' => "tenant={$instance->slug} has no headscale_user_id — cannot rotate. Check headscale_instances row."],
                ]);
                continue;
            }

            $p = new Process([
                'headscale', '--config', $configPath, 'preauthkeys', 'create',
                '--user', (string) $instance->headscale_user_id,
                '--reusable', '--expiration', '8760h', '--output', 'json',
            ], null, null, null, 30);
            $p->run();

            if (!$p->isSuccessful()) {
                \App\Support\Health\OperatorAlert::send('Headscale key rotation FAILED', [
                    ['level' => 'critical', 'area' => 'headscale',
                     'message' => "tenant={$instance->slug} error=" . trim($p->getErrorOutput())],
                ]);
                continue;
            }

            $data = json_decode($p->getOutput(), true);
            $newKey = $data['key'] ?? null;

            if (!$newKey) {
                \App\Support\Health\OperatorAlert::send('Headscale key rotation FAILED — could not parse new key', [
                    ['level' => 'critical', 'area' => 'headscale',
                     'message' => "tenant={$instance->slug} output=" . substr($p->getOutput(), 0, 300)],
                ]);
                continue;
            }

            DB::table('headscale_instances')->where('id', $instance->id)->update([
                'preauth_key_encrypted' => Crypt::encryptString($newKey),
                'preauth_key_expires_at' => now()->addYear(),
                'updated_at' => now(),
            ]);

            \App\Support\Audit::log($instance->tenant_id, 'system', 'headscale.preauth_key_rotated', 'success', 'headscale', [
                'slug' => $instance->slug,
            ]);

            $this->info("Rotated pre-auth key for {$instance->slug} (old key remains valid until its own expiry).");
        }

        return self::SUCCESS;
    }
}
