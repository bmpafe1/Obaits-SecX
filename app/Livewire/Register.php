<?php
namespace App\Livewire;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Validation\Rule;
 
#[Layout('components.layouts.app')]
class Register extends Component
{
    public string $first_name = '';
    public string $last_name = '';
    public string $email = '';
    //public string $password = '';
    public string $phone = '';
    public string $organization_name = '';
    public string $organization_website = '';
    // Country is asked at registration rather than first login because the
    // phone number is validated against E.164 here, and a country makes that
    // intelligible to the person filling the form.
    public string $country = '';
    public bool $acknowledge_mismatch = false;
 
    // Public providers rejected as non-professional. Edit as needed.
    private array $publicProviders = [
        'gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com',
        'icloud.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com',
        'yandex.com', 'mail.com', 'zoho.com',
    ];
 
    protected function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:255',
                Rule::unique('tenant_users', 'email'),
                function ($attr, $value, $fail) {
                    $emailDomain = \App\Support\DomainHelper::fromEmail($value);
                    if (in_array($emailDomain, $this->publicProviders)) {
                        $fail(__('Please use your professional email address.'));
                    }
                },
            ],
            //'password' => 'required|string|min:10',
            // E.164, required. A number without a country code cannot be
            // dialled from abroad -- confirmed on the existing rows, which are
            // all bare local numbers -- and marketplace referrals send it to
            // providers who may be in another country entirely.
            //
            // Spaces, hyphens and brackets are accepted on input and stripped
            // on save: people type +237 675 382 735, machines want
            // +237675382735.
            'phone' => ['required', 'string', 'max:40',
                'regex:/^\\+[0-9][0-9 ()\\-]{7,20}$/'],
            'country' => 'required|string|size:2',
            'organization_name' => 'required|string|max:200',
            'organization_website' => 'required|string|max:255',
        ];
    }
 
    public function register()
    {
        $data = $this->validate();

        $emailDomain = \App\Support\DomainHelper::fromEmail($data['email']);
        $siteDomain  = \App\Support\DomainHelper::fromWebsite($data['organization_website']);

        // Friendly check: is this organization's domain already registered?
        $domainTaken = \Illuminate\Support\Facades\DB::table('tenants')
            ->where('primary_domain', $siteDomain ?: $emailDomain)
            ->exists();
        if ($domainTaken) {
            $this->addError('organization_website', __('An account for this organization already exists. Please contact your administrator to be invited, or use a different organization.'));
            return;
        }

        $mismatch = ($siteDomain && $emailDomain && $emailDomain !== $siteDomain);
        if ($mismatch && !$this->acknowledge_mismatch) {
            $this->addError('email', __('Your email domain does not match your website. Check the box below to confirm this is correct.'));
            return;
        }
 
        $slug = \App\Support\DomainHelper::slug($siteDomain ?: $emailDomain);
        $base = $slug; $n = 1;
        while (\Illuminate\Support\Facades\DB::table('tenants')->where('slug', $slug)->exists()) {
            $n++; $slug = $base . '-' . $n;
        }
 
        $tenantId = (string) \Illuminate\Support\Str::uuid();
        $emailCode = (string) random_int(100000, 999999);

        // All three inserts succeed together or roll back together — no half-created tenant.
        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $tenantId, $slug, $siteDomain, $emailDomain, $mismatch, $emailCode) {
            \Illuminate\Support\Facades\DB::table('tenants')->insert([
                'id' => $tenantId,
                'slug' => $slug,
                'organization_name' => $data['organization_name'],
                'primary_domain' => $siteDomain ?: $emailDomain,
                'preferred_language' => app()->getLocale(),
                'status' => 'pending_email',
                'email_verify_code' => $emailCode,
                'email_verified' => false,
                'email_domain_mismatch' => $mismatch,
                // 30 days, not 90. After that a tenant pays for the number
                // of devices they want to enrol.
                'trial_ends_at' => now()->addDays(30),
                'created_at' => now(), 'updated_at' => now(),
            ]);
 
            \Illuminate\Support\Facades\DB::table('tenant_users')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $tenantId,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => preg_replace('/[^0-9+]/', '', $data['phone']),
                'role' => 'platform-admin',
                'created_at' => now(), 'updated_at' => now(),
            ]);
 
            // Seed the profile so country and phone are held from day one.
            // Nothing populated this table before -- one empty row existed
            // across four tenants -- so every downstream feature that wanted a
            // country found none. Sector, headcount and description follow at
            // first login.
            \Illuminate\Support\Facades\DB::table('tenant_profiles')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $tenantId,
                'legal_name' => $data['organization_name'],
                'country' => strtoupper($data['country']),
                'phone' => preg_replace('/[^0-9+]/', '', $data['phone']),
                'contact_email' => $data['email'],
                'website' => $data['organization_website'],
                'created_at' => now(), 'updated_at' => now(),
            ]);

            \Illuminate\Support\Facades\DB::table('subscriptions')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $tenantId,
                // Trial allows 10 devices. Two was too tight to evaluate on:
                // a prospect cannot judge endpoint management, policies or
                // scanning across a pair of machines, and the trial exists to
                // let them decide.
                'tier' => 'trial', 'asset_limit' => 10,
                'current_period_ends_at' => now()->addDays(30),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
 
        \Illuminate\Support\Facades\Mail::to($data['email'])
            ->send(new \App\Mail\VerifyEmail($data['first_name'], $emailCode));
 
        if ($mismatch) {
            \App\Support\Audit::log($tenantId, 'system', 'registration.email_mismatch_ack', 'success', null,
                ['email_domain' => $emailDomain, 'site_domain' => $siteDomain]);
        }
 
        session(['pending_tenant' => $tenantId]);
        return redirect('/verify-email');
    }
 
    public function render()
    {
        return view('livewire.register');
    }
}