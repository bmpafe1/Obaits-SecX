<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Support\Perm;
use App\Support\Audit;
use App\Support\TenantMailer;

/**
 * Let a tenant send platform mail from their own server.
 *
 * ENABLING REQUIRES A PASSING TEST, deliberately. Wrong SMTP settings are
 * SILENTLY wrong -- the whole reason this needs care -- and allowing an
 * untested configuration to be enabled would recreate exactly the problem the
 * test exists to prevent. A security alert is the wrong message to discover a
 * typo with.
 *
 * The password is never rendered back to the form. A stored one shows as a
 * placeholder and is only replaced when a new value is typed.
 */
#[Layout('components.layouts.portal')]
class MailSettings extends Component
{
    public string $tenantId;

    public bool   $enabled = false;
    public string $host = '';
    public string $port = '587';
    public string $encryption = 'tls';
    public string $username = '';
    public string $password = '';
    public string $from_address = '';
    public string $from_name = '';

    public bool $hasStoredPassword = false;
    public ?string $testResult = null;
    public bool $testOk = false;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }

        $s = DB::table('tenant_mail_settings')->where('tenant_id', $this->tenantId)->first();
        if (!$s) { return; }

        $this->enabled = (bool) $s->enabled;
        $this->host = $s->host;
        $this->port = (string) $s->port;
        $this->encryption = $s->encryption;
        $this->username = (string) $s->username;
        $this->from_address = $s->from_address;
        $this->from_name = (string) $s->from_name;
        $this->hasStoredPassword = !empty($s->password_encrypted);
    }

    protected function rules(): array
    {
        return [
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'encryption' => 'required|in:tls,ssl,none',
            'username' => 'nullable|string|max:255',
            'password' => ($this->hasStoredPassword ? 'nullable' : 'required') . '|string|max:255',
            'from_address' => 'required|email|max:255',
            'from_name' => 'nullable|string|max:120',
        ];
    }

    public function save(bool $thenTest = false)
    {
        Perm::require('manage_team');
        $this->validate();

        $values = [
            'host' => $this->host,
            'port' => (int) $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username ?: null,
            'from_address' => $this->from_address,
            'from_name' => $this->from_name ?: null,
            'updated_at' => now(),
        ];

        if ($this->password !== '') {
            $values['password_encrypted'] = Crypt::encryptString($this->password);
            // Settings changed: the previous test no longer proves anything.
            $values['last_test_ok'] = false;
        }

        $existing = DB::table('tenant_mail_settings')->where('tenant_id', $this->tenantId)->first();

        if ($existing) {
            DB::table('tenant_mail_settings')->where('id', $existing->id)->update($values);
        } else {
            DB::table('tenant_mail_settings')->insert($values + [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'enabled' => false,
                'created_at' => now(),
            ]);
        }

        $this->password = '';
        $this->hasStoredPassword = true;

        Audit::log($this->tenantId, 'portal', 'mail_settings.saved', 'success', null, ['host' => $this->host]);

        if ($thenTest) { $this->test(); return; }

        // Only tell them to test if the test state was actually invalidated --
        // which happens when the password changes. Telling someone to test
        // when they already have is the kind of message that teaches people to
        // ignore every other message.
        $current = DB::table('tenant_mail_settings')->where('tenant_id', $this->tenantId)->first();

        if ($current && $current->last_test_ok) {
            $this->testResult = __('Saved.');
            $this->testOk = true;
        } else {
            $this->testResult = __('Saved. Test the connection before enabling it.');
            $this->testOk = false;
        }
    }

    /**
     * Send a real message through their server, to the person configuring it.
     * Anything less proves nothing: credentials can be accepted and delivery
     * still fail.
     */
    public function test()
    {
        Perm::require('manage_team');

        $s = DB::table('tenant_mail_settings')->where('tenant_id', $this->tenantId)->first();
        if (!$s) {
            $this->testResult = __('Save your settings first.');
            return;
        }

        $to = session('auth_email');

        try {
            TenantMailer::configure($s);

            Mail::mailer('tenant')->to($to)->send(new \App\Mail\NotificationMail(
                __('Obaits test message'),
                __('This message was sent through your own mail server. If you received it, your settings are working.'),
                null
            ));

            DB::table('tenant_mail_settings')->where('id', $s->id)->update([
                'last_tested_at' => now(), 'last_test_ok' => true,
                'last_test_error' => null, 'failure_count' => 0,
                'updated_at' => now(),
            ]);

            $this->testOk = true;
            $this->testResult = __('Sent. Check :email — if it arrived, you can enable this.', ['email' => $to]);
        } catch (\Throwable $e) {
            DB::table('tenant_mail_settings')->where('id', $s->id)->update([
                'last_tested_at' => now(), 'last_test_ok' => false,
                'last_test_error' => mb_substr($e->getMessage(), 0, 250),
                'enabled' => false,       // a failing server must not stay enabled
                'updated_at' => now(),
            ]);

            $this->enabled = false;
            $this->testOk = false;
            $this->testResult = __('Could not send: :error', ['error' => Str::limit($e->getMessage(), 160)]);
        }
    }

    public function toggleEnabled()
    {
        Perm::require('manage_team');

        $s = DB::table('tenant_mail_settings')->where('tenant_id', $this->tenantId)->first();
        if (!$s) { return; }

        if (!$s->enabled && !$s->last_test_ok) {
            $this->testResult = __('Test the connection successfully before enabling it.');
            $this->enabled = false;
            return;
        }

        DB::table('tenant_mail_settings')->where('id', $s->id)
            ->update(['enabled' => !$s->enabled, 'failure_count' => 0, 'updated_at' => now()]);

        $this->enabled = !$s->enabled;

        Audit::log($this->tenantId, 'portal', 'mail_settings.' . ($this->enabled ? 'enabled' : 'disabled'),
            'success', null, ['host' => $s->host]);
    }

    public function render()
    {
        return view('livewire.portal.mail-settings', [
            'settings' => DB::table('tenant_mail_settings')->where('tenant_id', $this->tenantId)->first(),
        ]);
    }
}
