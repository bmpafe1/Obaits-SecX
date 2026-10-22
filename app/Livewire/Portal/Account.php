<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

#[Layout('components.layouts.portal')]
class Account extends Component
{
    public string $locale = '';
    public string $licenceKey = '';
    // --- AI provider -------------------------------------------------------
    public string $aiProvider = 'platform';   // platform|claude|deepseek
    public string $aiKey = '';                // blank means "leave as it is"
    public string $aiModel = '';
    public string $aiNotice = '';
    // A failure shown in a green success box is a small dishonesty. The view
    // styles the notice from this.
    public bool $aiNoticeOk = true;
    public ?string $licenceMsg = null;
    public bool $licenceOk = false;

    public function mount()
    {
        if (!session('tenant_id')) { redirect('/login'); }
        $this->locale = session('locale', app()->getLocale());

        // Load the tenant's saved AI provider so the form opens on their current
        // choice. The KEY is never loaded back — it is write-only from the
        // browser's point of view, and a blank field means "keep the stored one".
        $s = DB::table('tenant_ai_settings')->where('tenant_id', session('tenant_id'))->first();
        if ($s) {
            $this->aiProvider = $s->provider;
            $this->aiModel    = (string) $s->model;
        }
    }

    public function setLocale(string $locale)
    {
        if (!in_array($locale, ['en','fr','es','de','nl'], true)) return;
        session(['locale' => $locale]);
        app()->setLocale($locale);
        session()->flash('acct_ok', __('Language updated.'));
    }

    /**
     * Save the tenant's own AI provider.
     *
     * The key is encrypted at rest and never sent back to the browser. A blank
     * key on an existing configuration means "keep the current one", so a tenant
     * can change the model without re-entering their key.
     */
    public function saveAiSettings()
    {
        $this->aiNotice = '';
        $this->aiNoticeOk = true;
        $tenantId = session('tenant_id');

        if (!in_array($this->aiProvider, ['platform', 'claude', 'deepseek'], true)) {
            $this->aiNoticeOk = false;
            $this->aiNotice = __('Please choose a valid provider.');
            return;
        }

        $existing = DB::table('tenant_ai_settings')->where('tenant_id', $tenantId)->first();

        if ($this->aiProvider === 'platform') {
            // Revert to the platform's provider and DISCARD the stored key. We do
            // not keep a credential the tenant has stopped using.
            DB::table('tenant_ai_settings')->updateOrInsert(
                ['tenant_id' => $tenantId],
                ['provider' => 'platform', 'api_key' => null, 'model' => null,
                 'last_verified_at' => null, 'last_verify_result' => null,
                 'updated_at' => now(), 'created_at' => $existing->created_at ?? now()]
            );
            $this->aiKey = '';
            $this->aiNotice = __('Switched back to the platform’s AI. Your key has been removed.');
            return;
        }

        if ($this->aiKey === '' && !($existing && $existing->api_key)) {
            $this->aiNoticeOk = false;
            $this->aiNotice = __('Please enter your API key.');
            return;
        }

        $row = [
            'provider'   => $this->aiProvider,
            'model'      => trim($this->aiModel) ?: null,
            'updated_at' => now(),
            'created_at' => $existing->created_at ?? now(),
        ];

        if ($this->aiKey !== '') {
            $row['api_key'] = Crypt::encryptString(trim($this->aiKey));
            $row['last_verified_at'] = null;      // a new key is unproven
            $row['last_verify_result'] = null;
        }

        DB::table('tenant_ai_settings')->updateOrInsert(['tenant_id' => $tenantId], $row);

        \App\Support\Audit::log($tenantId, session('auth_email'), 'ai.settings.saved', 'success', 'llm',
            ['provider' => $this->aiProvider, 'model' => $row['model']]);   // never the key

        $this->aiKey = '';
        $this->aiNotice = __('Saved. Use “Test” to confirm the key works.');
    }

    /**
     * Make a real call with the tenant's key.
     *
     * Storing a key proves nothing. A tenant whose key is wrong or out of credit
     * needs to know now, not when a document generation quietly returns an error
     * message instead of a policy.
     */
    public function testAiSettings()
    {
        $tenantId = session('tenant_id');
        $this->aiNotice = '';

        \App\Support\Llm\LlmContext::set($tenantId);
        try {
            $client = app(\App\Support\Llm\LlmClient::class);
            $reply  = $client->chat([
                ['role' => 'system', 'content' => 'Answer with one word.'],
                ['role' => 'user',   'content' => 'Reply with the word: working'],
            ]);
        } finally {
            \App\Support\Llm\LlmContext::clear();
        }

        $ok = stripos($reply, 'working') !== false;

        DB::table('tenant_ai_settings')->where('tenant_id', $tenantId)->update([
            'last_verified_at'   => now(),
            'last_verify_result' => $ok ? 'ok' : 'failed',
            'updated_at'         => now(),
        ]);

        // On failure show what the provider actually said — "your key was
        // rejected" is something a tenant can fix in two minutes; a generic
        // failure sends them to support instead.
        $this->aiNoticeOk = $ok;
        $this->aiNotice = $ok
            ? __('Working. Your AI provider answered correctly.')
            : __('That did not work: ') . $reply;
    }

    public function render()
    {
        return view('livewire.portal.account', [
            // Passed so the view can show provider, model and the last test
            // result — never the key itself.
            'aiSettings' => DB::table('tenant_ai_settings')
                ->where('tenant_id', session('tenant_id'))
                ->first(),
        ]);
    }

    public function activateLicence()
    {
        if (!session('tenant_id')) return;
        $tenantId = session('tenant_id');

        // Anti-probe rate limit: 5 attempts / 10 min per tenant.
        $rlKey = 'licence_activate:' . $tenantId;
        if ((int) \Illuminate\Support\Facades\Cache::get($rlKey, 0) >= 5) {
            $this->licenceOk = false;
            $this->licenceMsg = __('Too many attempts. Please wait a few minutes and try again.');
            return;
        }
        \Illuminate\Support\Facades\Cache::put($rlKey,
            (int) \Illuminate\Support\Facades\Cache::get($rlKey, 0) + 1, now()->addMinutes(10));

        $result = app(\App\Support\Licensing\DropboxLicenceFetcher::class)
            ->verifyKeyForTenant($tenantId, $this->licenceKey);

        if (!($result['ok'] ?? false)) {
            $this->licenceOk = false;
            $this->licenceMsg = match ($result['reason'] ?? '') {
                'no_key'         => __('Please enter your licence key.'),
                'no_match'       => __('That key does not match your account\'s licence. Please check the key you were issued.'),
                'key_not_unique' => __('There is a problem with this key on our side. Support has been notified — please try again shortly.'),
                'file_unreachable', 'file_unreadable'
                                 => __('We could not verify your key just now. Please try again shortly — your access will also renew automatically within the hour.'),
                'row_invalid'    => __('Your licence details could not be read. Please contact support.'),
                default          => __('That key could not be verified. Please check it and try again.'),
            };
            \App\Support\Audit::log($tenantId, session('auth_email'),
                'licence.activate_failed', 'blocked', 'obaits', ['reason' => $result['reason'] ?? 'unknown']);
            return;
        }

        // MATCH — activate immediately from the tenant's own verified row.
        \Illuminate\Support\Facades\DB::table('license_cache')->updateOrInsert(
            ['tenant_id' => $tenantId],
            ['expires_on' => $result['expires_on'], 'grace_ends_at' => null,
             'last_reminder_stage' => null, 'updated_at' => now()]
        );
        \Illuminate\Support\Facades\DB::table('tenants')->where('id', $tenantId)
            ->where('status', 'license_expired')
            ->update(['status' => 'active', 'updated_at' => now()]);

        \App\Support\Audit::log($tenantId, session('auth_email'),
            'licence.activated', 'success', 'obaits', ['expires_on' => $result['expires_on']]);

        $this->licenceOk = true;
        $this->licenceKey = '';
        $this->licenceMsg = __('Your licence is confirmed. Access is active until :date.',
            ['date' => \Illuminate\Support\Carbon::parse($result['expires_on'])->toFormattedDateString()]);
    }
}