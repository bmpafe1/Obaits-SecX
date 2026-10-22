<div>
    <h1 style="color:#0B2545;">{{ __('Web application firewall') }}</h1>
    <p style="color:#5A6B82;">
        {{ __('A firewall that sits in front of your website and blocks attacks. It runs on your own server — your traffic never passes through Obaits.') }}
    </p>

    @if (session('w_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('w_ok') }}</div>
    @endif

    @php $canEdit = \App\Support\Perm::can('manage_team'); @endphp

    {{-- ============ YOUR WEB SERVERS ============ --}}
    @forelse ($deployments as $d)
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">

        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
            <div>
                <strong style="color:#0B2545;font-size:1.05rem;">{{ $d->label }}</strong>
                <p style="color:#5A6B82;font-size:.85rem;margin:.2rem 0;">
                    {{ $d->hostname }} · {{ strtoupper($d->server_type) }}
                    @if ($d->crs_version) · {{ __('Rule set') }} v{{ $d->crs_version }} @endif
                </p>
            </div>
            @php
                $badge = match ($d->mode) {
                    'blocking'  => ['#1e824c', __('Blocking attacks')],
                    'detection' => ['#b8860b', __('Watching only')],
                    default     => ['#c0392b', __('Not protected')],
                };
            @endphp
            <span style="background:{{ $badge[0] }};color:#fff;border-radius:4px;padding:.15rem .6rem;font-size:.8rem;white-space:nowrap;">
                {{ $badge[1] }}
            </span>
        </div>

        {{-- ---------- NOT YET INSTALLED ---------- --}}
        @if ($d->mode === 'pending')
            <div style="background:#fdecea;border-left:3px solid #c0392b;padding:1rem;border-radius:6px;margin-top:1rem;">
                <strong style="color:#c0392b;">{{ __('This server has no firewall yet.') }}</strong>
                <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0;">
                    {{ __('Run the installer below on that server. It installs the firewall in watching mode — it will not block anything until you say so.') }}
                </p>
                @if ($canEdit)
                    <button wire:click="showScript('{{ $d->id }}')"
                        style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.5rem 1.1rem;cursor:pointer;">
                        {{ __('Show me the installer') }}
                    </button>
                @endif
            </div>

            @if ($showingScriptFor === $d->id)
                <div style="margin-top:1rem;">
                    @if ($d->server_type === 'iis')
                        <div style="background:#fff8e1;border-left:3px solid #b8860b;padding:.8rem;border-radius:6px;margin-bottom:.8rem;">
                            <strong>{{ __('A note about Windows/IIS.') }}</strong>
                            <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0 0;">
                                {{ __('The best firewall for IIS runs as a small nginx service in front of it, on the same machine. This means changing which port IIS listens on. It is a real change to your setup and needs a maintenance window. Ask Brian to walk you through it.') }}
                            </p>
                        </div>
                    @endif

                    <p style="color:#5A6B82;font-size:.85rem;">
                        {{ __('Copy this and run it on :h as an administrator.', ['h' => $d->hostname]) }}
                    </p>
                    <textarea readonly rows="14"
                        style="width:100%;padding:.8rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;font-size:.78rem;background:#f8f9fb;">{{ $script }}</textarea>

                    @if ($canEdit)
                        <button wire:click="markInstalled('{{ $d->id }}')"
                            style="background:#1e824c;color:#fff;border:none;border-radius:8px;padding:.5rem 1.1rem;margin-top:.6rem;cursor:pointer;">
                            {{ __('I have run this — mark as installed') }}
                        </button>
                    @endif
                </div>
            @endif
        @endif

        {{-- ---------- DETECTION MODE: the mode switch, with its warning ---------- --}}
        @if ($d->mode === 'detection')
            <div style="background:#fff8e1;border-left:3px solid #b8860b;padding:1rem;border-radius:6px;margin-top:1rem;">
                <strong style="color:#0B2545;">{{ __('This server is watching, but not blocking.') }}</strong>

                <p style="color:#5A6B82;font-size:.9rem;margin:.4rem 0;">
                    {{ __('Attacks are being recorded but NOT stopped. That is deliberate. A new firewall often mistakes normal activity for an attack — it might flag your own staff. Leave it watching for a couple of weeks and check the alerts.') }}
                </p>

                <p style="color:#c0392b;font-size:.9rem;margin:.4rem 0;">
                    <strong>{{ __('Before you switch to blocking:') }}</strong>
                    {{ __('once blocking is on, anything matching a rule will be REFUSED. If a rule is wrong, real users — your own staff, your own customers — will be turned away. Read the alerts first and make sure only real attacks are being flagged.') }}
                </p>

                @if ($canEdit)
                    <button wire:click="enableBlocking('{{ $d->id }}')"
                        wire:confirm="{{ __('Are you sure? If a rule is mistuned, legitimate users could be blocked from your site.') }}"
                        style="background:#c0392b;color:#fff;border:none;border-radius:8px;padding:.5rem 1.1rem;cursor:pointer;">
                        {{ __('Switch to blocking') }}
                    </button>
                @endif
            </div>
        @endif

        {{-- ---------- BLOCKING MODE ---------- --}}
        @if ($d->mode === 'blocking')
            <div style="background:#eef7f0;border-left:3px solid #1e824c;padding:1rem;border-radius:6px;margin-top:1rem;">
                <strong style="color:#1e824c;">{{ __('This server is blocking attacks.') }}</strong>
                <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0;">
                    {{ __('If users report being unable to use the site, switch back to watching immediately and check the alerts — a rule may be flagging legitimate traffic.') }}
                </p>
                @if ($canEdit)
                    <button wire:click="backToDetection('{{ $d->id }}')"
                        style="background:none;border:1px solid #b8860b;color:#b8860b;border-radius:8px;padding:.4rem 1rem;cursor:pointer;">
                        {{ __('Stop blocking (back to watching)') }}
                    </button>
                @endif
            </div>
        @endif

        @if ($canEdit)
            <button wire:click="removeServer('{{ $d->id }}')" wire:confirm="{{ __('Remove this server?') }}"
                style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:.8rem;margin-top:.8rem;padding:0;">
                {{ __('Remove this server') }}
            </button>
        @endif
    </div>

    @empty
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <p style="color:#5A6B82;margin:0;">
            {{ __('You have not added any web servers yet. If you run a website or a web application, add it below and we will give you a firewall for it.') }}
        </p>
    </div>
    @endforelse

    {{-- ============ ADD A SERVER ============ --}}
    @if ($canEdit)
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Add a web server') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('Tell us about a website or web application you run, and we will give you a firewall for it. Not sure what it runs on? Ask Brian.') }}
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.7rem;align-items:end;">
            <label>{{ __('What is it?') }}
                <input wire:model="label" placeholder="{{ __('e.g. Internet banking') }}"
                    style="width:100%;padding:.45rem;border:1px solid #ddd;border-radius:6px;">
            </label>
            <label>{{ __('Address') }}
                <input wire:model="hostname" placeholder="{{ __('e.g. banking.example.com') }}"
                    style="width:100%;padding:.45rem;border:1px solid #ddd;border-radius:6px;">
            </label>
            <label>{{ __('What does it run on?') }}
                <select wire:model="server_type" style="width:100%;padding:.45rem;border:1px solid #ddd;border-radius:6px;">
                    <option value="nginx">nginx</option>
                    <option value="apache">Apache</option>
                    <option value="iis">Windows / IIS</option>
                </select>
            </label>
            <button wire:click="addServer"
                style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.5rem 1.1rem;cursor:pointer;">
                {{ __('Add') }}
            </button>
        </div>

        @error('label') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        @error('hostname') <small style="color:#c0392b;">{{ $message }}</small> @enderror
    </div>
    @endif
</div>
