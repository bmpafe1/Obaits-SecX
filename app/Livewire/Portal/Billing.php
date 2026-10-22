<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * What a tenant is on, what they are using, and what happens next.
 *
 * READ-ONLY for now. Checkout and capacity purchase follow; this exists first
 * because asset_limit is enforced at enrolment as of 2026-08-29 and a tenant
 * currently has NO WAY to see what their limit is or how close they are to it.
 * Being refused at 402 without ever having been shown the number is a poor
 * experience, and it is the cheapest half of the problem to fix.
 *
 * The trial countdown is the other reason. trial_ends_at has been written since
 * registration existed and read by nothing, so a trial silently never ends.
 * Showing it is the first step toward it meaning something.
 */
#[Layout('components.layouts.portal')]
class Billing extends Component
{
    public string $tenantId;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }
    }

    /**
     * What the tenant should be told, in the order of urgency they would care.
     * Returns [level, headline, detail] or null when nothing needs saying.
     */
    public static function notice(object $sub, ?object $tenant): ?array
    {
        $status = $sub->status ?? 'trialing';

        if ($status === 'suspended' || ($tenant->status ?? null) === 'suspended') {
            return ['critical', __('Your access is suspended'),
                __('Settle the outstanding amount to restore access. Your devices stay enrolled and your data is untouched.')];
        }

        if ($status === 'past_due') {
            $days = $sub->grace_ends_at
                ? max(0, (int) now()->diffInDays(Carbon::parse($sub->grace_ends_at), false))
                : null;
            return ['critical', __('Payment is overdue'),
                $days !== null
                    ? __('Your access continues for :n more day(s). After that it will be suspended until payment is received.', ['n' => $days])
                    : __('Please settle the outstanding amount to avoid interruption.')];
        }

        if ($status === 'trialing' && !empty($tenant->trial_ends_at)) {
            $ends = Carbon::parse($tenant->trial_ends_at);
            $days = (int) now()->diffInDays($ends, false);

            if ($days < 0) {
                return ['critical', __('Your trial has ended'),
                    __('Choose a plan to keep using the platform.')];
            }
            if ($days <= 7) {
                return ['warning', __('Your trial ends in :n day(s)', ['n' => $days]),
                    __('Choose a plan to continue without interruption. Nothing is lost if you decide later — your devices stay enrolled.')];
            }
            return ['info', __('You are on a free trial'),
                __('It ends on :date. No card is needed until then.', ['date' => $ends->toFormattedDateString()])];
        }

        return null;
    }

    public function render()
    {
        $sub = DB::table('subscriptions')->where('tenant_id', $this->tenantId)->first();
        $tenant = DB::table('tenants')->where('id', $this->tenantId)->first();

        // Active credentials, matching exactly what enrolment counts against --
        // a number here that disagrees with the enforcement would be worse than
        // showing nothing.
        $devicesUsed = DB::table('device_credentials')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('revoked_at')
            ->count();

        $invoices = DB::table('invoices')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('voided_at')
            ->orderByDesc('created_at')
            ->limit(24)
            ->get();

        $payments = DB::table('subscription_payments')
            ->where('tenant_id', $this->tenantId)
            ->orderByDesc('created_at')
            ->limit(12)
            ->get();

        return view('livewire.portal.billing', [
            'sub' => $sub,
            'tenant' => $tenant,
            'devicesUsed' => $devicesUsed,
            'limit' => $sub->asset_limit ?? null,
            'notice' => $sub ? self::notice($sub, $tenant) : null,
            'invoices' => $invoices,
            'payments' => $payments,
        ]);
    }
}
