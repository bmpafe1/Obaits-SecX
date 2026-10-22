<?php
namespace App\Support;
 
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
 
class Notifications
{
    // Raise a notification for a tenant (optionally a specific user).
    public static function raise(string $tenantId, string $title, array $opts = []): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'tenant_user_id' => $opts['user_id'] ?? null,
            'level' => $opts['level'] ?? 'info',
            'title' => $title,
            'body'  => $opts['body'] ?? null,
            'link'  => $opts['link'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
 
    public static function unreadFor(string $tenantId, ?string $userId): \Illuminate\Support\Collection
    {
        return DB::table('notifications')
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('tenant_user_id')->orWhere('tenant_user_id', $userId))
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->limit(20)->get();
    }
 
    public static function markRead(string $tenantId, string $id): void
    {
        DB::table('notifications')->where('tenant_id', $tenantId)->where('id', $id)
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    // Raise a notification AND email the tenant's people.
    /**
     * Notify only the people who can act on billing.
     *
     * raiseAndEmail() sends to EVERY tenant user, which is right for a security
     * alert and wrong for money: a tenant with five staff would send an invoice
     * and a payment receipt to analysts and viewers who have no business seeing
     * what the organisation pays.
     *
     * Targets the role holding manage_billing -- currently platform-admin --
     * so the rule follows the permission model rather than inventing a second
     * one. An extra address can be added for the person who actually made a
     * purchase, so they get their own receipt even if the role changes later.
     *
     * The IN-APP notification still goes to the tenant as a whole; only the
     * email is narrowed.
     */
    public static function raiseAndEmailBilling(string $tenantId, string $title, array $opts = [], ?string $alsoTo = null): void
    {
        self::raise($tenantId, $title, $opts);

        $roles = array_keys(array_filter(
            \App\Support\Perm::matrix(),
            fn ($abilities) => in_array('manage_billing', $abilities, true)
        ));

        $emails = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->whereIn('role', $roles)
            ->pluck('email')->filter()->all();

        if ($alsoTo) { $emails[] = $alsoTo; }
        $emails = array_values(array_unique($emails));

        if (empty($emails)) {
            // No one can act on this. Worth knowing: an invoice with no
            // recipient is an invoice that will not be paid.
            \Illuminate\Support\Facades\Log::warning(
                'Billing email had no recipient', ['tenant' => $tenantId, 'title' => $title]
            );
            return;
        }

        try {
            \Illuminate\Support\Facades\Mail::to($emails)->send(
                new \App\Mail\NotificationMail($title, $opts['body'] ?? '', $opts['link'] ?? null)
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Billing email failed: ' . $e->getMessage());
        }
    }

    public static function raiseAndEmail(string $tenantId, string $title, array $opts = []): void
    {
        self::raise($tenantId, $title, $opts);

        $emails = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->pluck('email')
            ->filter()
            ->all();

        if (empty($emails)) return;

        try {
            // Sent from the TENANT'S OWN server where they have configured one
            // and proven it works. This is mail about their estate, to their
            // people -- it belongs on their domain. TenantMailer falls back to
            // the platform sender if theirs fails, and records the failure.
            //
            // Billing, registration verification and marketplace referrals do
            // NOT come through here: those are from Obaits, not from them.
            \App\Support\TenantMailer::send($tenantId, $emails,
                new \App\Mail\NotificationMail(
                    $title,
                    $opts['body'] ?? '',
                    $opts['link'] ?? null
                )
            );
        } catch (\Throwable $e) {
            // Email must never break the app — the in-app notification already landed.
            \Illuminate\Support\Facades\Log::warning('Notification email failed: ' . $e->getMessage());
        }
    }
}
