<div wire:poll.10s>
    <h1 style="color:#0B2545;">{{ __('Web scan login') }}</h1>
    <p style="color:#5A6B82;">
        {{ __('Most of a website sits behind its login, and a scan that cannot log in only ever tests the front door. Give us a login here and scans will reach the pages that matter.') }}
    </p>

    @if ($notice)
        <div style="background:#eef7f0;border-left:4px solid #1e824c;padding:.8rem;border-radius:6px;margin:1rem 0;">{{ $notice }}</div>
    @endif

    {{-- ---------- existing profiles ---------- --}}
    @if (count($profiles))
        <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin:1rem 0;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                <thead><tr style="text-align:left;color:#5A6B82;background:#f8f9fb;">
                    <th style="padding:.6rem;">{{ __('Name') }}</th>
                    <th style="padding:.6rem;">{{ __('Site') }}</th>
                    <th style="padding:.6rem;width:45%;">{{ __('Can the scanner log in?') }}</th>
                    <th style="padding:.6rem;"></th>
                </tr></thead>
                <tbody>
                @foreach ($profiles as $p)
                    <tr style="border-top:1px solid #f2f2f2;vertical-align:top;">
                        <td style="padding:.6rem;color:#0B2545;"><strong>{{ $p->name }}</strong></td>
                        <td style="padding:.6rem;font-family:monospace;font-size:.8rem;word-break:break-all;">{{ $p->target_url }}</td>

                        {{-- ---------- the verdict, and how to act on it ---------- --}}
                        <td style="padding:.6rem;">
                            @php $ex = $this->explain($p->last_verify_reason); @endphp

                            @if ($p->last_verify_result === 'authenticated')
                                <span style="background:#eef7f0;color:#1e824c;border-radius:4px;padding:.15rem .5rem;font-size:.78rem;">{{ __('Yes') }}</span>
                                <div style="color:#5A6B82;font-size:.75rem;margin-top:.3rem;">
                                    {{ __('Logged out, the scanner saw :a pages. Logged in, it reached :b more.', ['a' => $p->last_anon_paths ?? '—', 'b' => $p->last_new_paths ?? '—']) }}
                                </div>

                            @elseif ($p->last_verify_result === 'failed')
                                <span style="background:#fdecea;color:#c0392b;border-radius:4px;padding:.15rem .5rem;font-size:.78rem;">{{ __('No') }}</span>
                                <div style="color:#c0392b;font-size:.75rem;margin-top:.3rem;">
                                    {{ __('Scans of this site are only testing pages anyone can already reach. Anything behind the login is untested.') }}
                                </div>

                            @elseif ($p->last_verify_result === 'testing')
                                <span style="color:#5A6B82;font-size:.8rem;">{{ __('Testing… this takes a few minutes.') }}</span>

                            @elseif ($p->last_verify_result === 'unknown')
                                <span style="background:#fff8e1;color:#b8860b;border-radius:4px;padding:.15rem .5rem;font-size:.78rem;">{{ __('Could not tell') }}</span>
                                <div style="color:#7a5c00;font-size:.75rem;margin-top:.3rem;">
                                    {{ __('Treat this as “No” until a test confirms otherwise.') }}
                                </div>

                            @else
                                <span style="color:#9aa;font-size:.8rem;">{{ __('Not tested yet') }}</span>
                                <div style="color:#9aa;font-size:.75rem;margin-top:.3rem;">
                                    {{ __('Test before relying on any scan of this site.') }}
                                </div>
                            @endif

                            @if ($ex['what'] || $p->last_verified_at)
                                <details style="margin-top:.5rem;">
                                    <summary style="cursor:pointer;color:#17A2B8;font-size:.75rem;">{{ __('what this means') }}</summary>

                                    @if ($ex['what'])
                                        <div style="font-size:.78rem;color:#333;margin:.4rem 0;">{{ $ex['what'] }}</div>
                                        <div style="font-size:.78rem;color:#5A6B82;"><strong>{{ __('To fix:') }}</strong> {{ $ex['fix'] }}</div>
                                    @endif

                                    @php $sample = json_decode($p->last_sample ?? '[]', true) ?: []; @endphp
                                    @if ($sample)
                                        <div style="font-size:.75rem;color:#0B2545;margin-top:.6rem;">
                                            <strong>{{ __('Pages the scanner reached only after logging in:') }}</strong>
                                        </div>
                                        <ul style="margin:.25rem 0;padding-left:1.1rem;font-size:.72rem;font-family:monospace;color:#5A6B82;word-break:break-all;">
                                            @foreach ($sample as $u)<li>{{ $u }}</li>@endforeach
                                        </ul>
                                        <div style="color:#9aa;font-size:.72rem;">
                                            {{ __('If these do not look like pages that need a login, the result may be misleading.') }}
                                        </div>
                                    @endif

                                    @if ($p->last_verified_at)
                                        <div style="color:#9aa;font-size:.72rem;margin-top:.5rem;">{{ __('Last tested') }}: {{ $p->last_verified_at }}</div>
                                    @endif
                                </details>
                            @endif
                        </td>

                        <td style="padding:.6rem;text-align:right;white-space:nowrap;">
                            <button wire:click="testLogin('{{ $p->id }}')"
                                style="background:#17A2B8;color:#fff;border:none;border-radius:6px;padding:.35rem .8rem;cursor:pointer;font-size:.8rem;">
                                {{ __('Test login') }}
                            </button>
                            <button wire:click="edit('{{ $p->id }}')"
                                style="background:none;border:none;color:#17A2B8;cursor:pointer;font-size:.8rem;">{{ __('edit') }}</button>
                            <button wire:click="delete('{{ $p->id }}')"
                                wire:confirm="{{ __('Delete this login?') }}"
                                style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:.8rem;">{{ __('delete') }}</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div style="color:#5A6B82;font-size:.78rem;margin:-.4rem 0 1rem;">
            {{ __('“Yes” means only that the scanner reached pages a logged-out visitor cannot. It does not mean the site is secure — that is what the scan itself is for.') }}
        </div>
    @endif

    {{-- ---------- the form ---------- --}}
    <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:1.4rem;margin:1.2rem 0;">
        <h3 style="color:#0B2545;margin-top:0;">{{ $editing ? __('Edit login') : __('Add a login') }}</h3>

        @if (!count($domains))
            <div style="background:#fff8e1;border-left:4px solid #b8860b;padding:1rem;border-radius:8px;">
                @if ($anyDomains)
                    {{ __('None of your domains are verified yet. We only scan sites you have proven you control.') }}
                @else
                    {{ __('Add and verify a domain first. We only scan sites you have proven you control.') }}
                @endif
                <a href="/portal/domains" style="color:#17A2B8;">{{ __('Go to domains →') }}</a>
            </div>
        @else
            <label style="display:block;margin:.6rem 0 .2rem;color:#0B2545;font-size:.85rem;">{{ __('Name this login') }}</label>
            <input type="text" wire:model="name" placeholder="{{ __('e.g. Customer portal') }}"
                style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:6px;">

            <label style="display:block;margin:.8rem 0 .2rem;color:#0B2545;font-size:.85rem;">{{ __('Which site') }}</label>
            <div style="display:flex;gap:.5rem;">
                <select wire:model="scheme" style="width:110px;padding:.55rem;border:1px solid #ddd;border-radius:6px;">
                    <option value="https">https://</option>
                    <option value="http">http://</option>
                </select>
                <select wire:model="domainId" style="flex:1;padding:.55rem;border:1px solid #ddd;border-radius:6px;">
                    <option value="">{{ __('Choose a verified domain…') }}</option>
                    @foreach ($domains as $d)
                        <option value="{{ $d->id }}">{{ $d->domain }}</option>
                    @endforeach
                </select>
                <input type="text" wire:model="port" placeholder="{{ __('port') }}"
                    style="width:90px;padding:.55rem;border:1px solid #ddd;border-radius:6px;">
            </div>
            <div style="color:#9aa;font-size:.75rem;margin-top:.2rem;">
                {{ __('Leave the port blank unless your site runs on a non-standard one.') }}
            </div>

            <div style="display:flex;gap:.8rem;">
                <div style="flex:1;">
                    <label style="display:block;margin:.8rem 0 .2rem;color:#0B2545;font-size:.85rem;">{{ __('Start scanning from') }}</label>
                    <input type="text" wire:model="targetPath" placeholder="/"
                        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;">
                </div>
                <div style="flex:1;">
                    <label style="display:block;margin:.8rem 0 .2rem;color:#0B2545;font-size:.85rem;">{{ __('Login page') }}</label>
                    <input type="text" wire:model="loginPath" placeholder="/login"
                        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;">
                </div>
            </div>

            <label style="display:block;margin:.8rem 0 .2rem;color:#0B2545;font-size:.85rem;">{{ __('What is the site built with?') }}</label>
            <select wire:model.live="preset" style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:6px;">
                @foreach ($presets as $k => $pre)
                    <option value="{{ $k }}">{{ $pre['label'] }}</option>
                @endforeach
            </select>
            <div style="color:#9aa;font-size:.75rem;margin-top:.2rem;">
                {{ __('This fills in the technical field names below. If you are not sure, choose Other and check them against your login page.') }}
            </div>

            <details style="margin:.8rem 0;">
                <summary style="cursor:pointer;color:#5A6B82;font-size:.85rem;">{{ __('Technical details') }}</summary>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.5rem;">
                    <div style="flex:1;min-width:150px;">
                        <label style="font-size:.78rem;color:#5A6B82;">{{ __('Username field') }}</label>
                        <input type="text" wire:model="usernameField" style="width:100%;padding:.45rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;font-size:.82rem;">
                    </div>
                    <div style="flex:1;min-width:150px;">
                        <label style="font-size:.78rem;color:#5A6B82;">{{ __('Password field') }}</label>
                        <input type="text" wire:model="passwordField" style="width:100%;padding:.45rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;font-size:.82rem;">
                    </div>
                    <div style="flex:1;min-width:150px;">
                        <label style="font-size:.78rem;color:#5A6B82;">{{ __('Security token field') }}</label>
                        <input type="text" wire:model="tokenName" style="width:100%;padding:.45rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;font-size:.82rem;">
                    </div>
                    <div style="flex:1;min-width:150px;">
                        <label style="font-size:.78rem;color:#5A6B82;">{{ __('Extra form data') }}</label>
                        <input type="text" wire:model="extraPostData" placeholder="Login=Login" style="width:100%;padding:.45rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;font-size:.82rem;">
                    </div>
                </div>
            </details>

            <label style="display:block;margin:.8rem 0 .2rem;color:#0B2545;font-size:.85rem;">{{ __('Text that only appears when logged in') }}</label>
            <input type="text" wire:model="loggedInRegex" placeholder="Logout"
                style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:6px;">
            <div style="color:#9aa;font-size:.75rem;margin-top:.2rem;">
                {{ __('Usually “Logout” or “Sign out”. Without this we cannot tell whether the login worked, and a scan could quietly test nothing.') }}
            </div>

            <div style="background:#fdf6e3;border-left:4px solid #b8860b;padding:.8rem;border-radius:6px;margin:1rem 0;font-size:.83rem;color:#7a5c00;">
                <strong>{{ __('Use an account created for this purpose.') }}</strong>
                {{ __('Not a real person’s account, and not an administrator unless you specifically want administrative pages tested. The scanner will submit forms, follow links and change data it finds.') }}
            </div>

            <div style="display:flex;gap:.8rem;">
                <div style="flex:1;">
                    <label style="display:block;margin:.2rem 0;color:#0B2545;font-size:.85rem;">{{ __('Username') }}</label>
                    <input type="text" wire:model="username" style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:6px;">
                </div>
                <div style="flex:1;">
                    <label style="display:block;margin:.2rem 0;color:#0B2545;font-size:.85rem;">{{ __('Password') }}</label>
                    <input type="password" wire:model="password" placeholder="{{ $editing ? __('leave blank to keep') : '' }}"
                        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:6px;">
                </div>
            </div>

            <button wire:click="save"
                style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.6rem 1.4rem;cursor:pointer;margin-top:1rem;">
                {{ $editing ? __('Save changes') : __('Save login') }}
            </button>
            @if ($editing)
                <button wire:click="$set('editing', null)" style="background:none;border:none;color:#5A6B82;cursor:pointer;margin-left:.5rem;">{{ __('cancel') }}</button>
            @endif
        @endif
    </div>
</div>