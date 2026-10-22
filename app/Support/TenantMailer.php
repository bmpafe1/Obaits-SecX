<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a message from the tenant's own SMTP server where they have one, and
 * from the platform where they do not -- or where theirs fails.
 *
 * ONLY FOR MAIL THAT IS GENUINELY THEIRS. Security alerts, scan reports,
 * notifications, team invites: about their estate, to their people, and better
 * arriving from their own domain.
 *
 * NOT for registration verification (no tenant exists yet), marketplace
 * referrals (the referral is made by Obaits, which is copied in and may
 * intervene) or anything billing (an invoice from us should come from us).
 * Those call Mail::to() directly and are unaffected by this class.
 *
 * FALLS BACK RATHER THAN FAILING, and records it. An undelivered security alert
 * is worse than one arriving from an unexpected sender. But a SILENT fallback
 * would let a tenant believe their SMTP works for months when it does not, so
 * every failure is counted and surfaced.
 */
class TenantMailer
{
    /**
     * @param string|array $to
     * @return string 'tenant' | 'platform'
     */
    public static function send(string $tenantId, $to, $mailable): string
    {
        $settings = self::settingsFor($tenantId);

        if (!$settings) {
            Mail::to($to)->send($mailable);
            return 'platform';
        }

        try {
            self::configure($settings);
            Mail::mailer('tenant')->to($to)->send($mailable);

            // Clear a previous failure: it is working again.
            if ($settings->failure_count > 0) {
                DB::table('tenant_mail_settings')->where('id', $settings->id)
                    ->update(['failure_count' => 0, 'last_failure_error' => null, 'updated_at' => now()]);
            }

            return 'tenant';
        } catch (\Throwable $e) {
            self::recordFailure($settings, $e->getMessage());

            // The message still goes out. Losing a security alert because a
            // customer's mail server is down would be the wrong trade.
            try {
                Mail::to($to)->send($mailable);
            } catch (\Throwable $inner) {
                Log::error('Both tenant and platform mail failed', [
                    'tenant' => $tenantId, 'error' => $inner->getMessage(),
                ]);
            }

            return 'platform';
        }
    }

    /** Enabled, tested, and not currently failing repeatedly. */
    public static function settingsFor(string $tenantId): ?object
    {
        $s = DB::table('tenant_mail_settings')
            ->where('tenant_id', $tenantId)->where('enabled', true)->first();

        if (!$s) { return null; }

        // Never trust a configuration that has not been proven to work. A
        // security alert is the wrong message to discover a typo with.
        if (!$s->last_test_ok) { return null; }

        // Stop trying after repeated failures rather than adding a timeout to
        // every message. The tenant is told; they re-test to re-enable.
        if ($s->failure_count >= 5) { return null; }

        return $s;
    }

    /** Configure the 'tenant' mailer at runtime for this message only. */
    public static function configure(object $s): void
    {
        config([
            'mail.mailers.tenant' => [
                'transport'  => 'smtp',
                'host'       => $s->host,
                'port'       => $s->port,
                'encryption' => $s->encryption === 'none' ? null : $s->encryption,
                'username'   => $s->username,
                'password'   => $s->password_encrypted ? Crypt::decryptString($s->password_encrypted) : null,
                'timeout'    => 15,
            ],
            'mail.from.address' => $s->from_address,
            'mail.from.name'    => $s->from_name ?: config('mail.from.name'),
        ]);
    }

    private static function recordFailure(object $s, string $error): void
    {
        $count = $s->failure_count + 1;

        DB::table('tenant_mail_settings')->where('id', $s->id)->update([
            'last_failure_at' => now(),
            'last_failure_error' => mb_substr($error, 0, 250),
            'failure_count' => $count,
            'updated_at' => now(),
        ]);

        Log::warning('Tenant SMTP failed; fell back to platform mail', [
            'tenant' => $s->tenant_id, 'failures' => $count, 'error' => $error,
        ]);

        // At the threshold, tell them once. Their mail is still being
        // delivered, but from our domain rather than theirs.
        if ($count === 5) {
            try {
                Notifications::raise($s->tenant_id, __('Your mail server is not working'), [
                    'level' => 'warning',
                    'body'  => __('We could not send through your own mail server, so messages are being sent from Obaits instead. Check your settings and test the connection to resume sending from your domain.'),
                    'link'  => '/portal/organisation',
                ]);
            } catch (\Throwable $e) {
                // Never let the notification break the send path.
            }
        }
    }
}
