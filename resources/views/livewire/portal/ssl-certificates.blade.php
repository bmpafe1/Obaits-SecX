<div>
    <h1 style="color:#0B2545;">{{ __('SSL Certificates') }}</h1>
    <p style="color:#5A6B82;">{{ __('Generate real, free, auto-renewing SSL certificates for your own domains.') }}</p>

    @if (session('ssl_error'))
        <div style="background:#fdecea;color:#c0392b;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('ssl_error') }}</div>
    @endif

    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Request a certificate') }}</h3>
        <form wire:submit="requestCertificate" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
            <input wire:model="newDomain" placeholder="{{ __('yourdomain.com') }}" style="flex:1;min-width:220px;padding:.5rem;border:1px solid #dfe3ea;border-radius:6px;">
            <button type="submit" style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;">{{ __('Request') }}</button>
        </form>
        @error('newDomain') <small style="color:#c0392b;">{{ $message }}</small> @enderror
    </div>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Your certificates') }}</h3>
        @if ($certificates->isEmpty())
            <p style="color:#5A6B82;">{{ __('No certificate requests yet.') }}</p>
        @else
            @foreach ($certificates as $cert)
                <div style="border-top:1px solid #f2f2f2;padding:1rem 0;">
                    <strong style="color:#0B2545;">{{ $cert->domain }}</strong>
                    <span style="margin-left:.5rem;background:{{ $cert->status === 'issued' ? '#eef7f0' : ($cert->status === 'failed' ? '#fdecea' : '#fff3cd') }};color:{{ $cert->status === 'issued' ? '#1e824c' : ($cert->status === 'failed' ? '#c0392b' : '#b8860b') }};border-radius:4px;padding:.1rem .5rem;font-size:.78rem;">{{ $cert->status }}</span>

                    @if ($cert->status === 'pending_dns')
                        <div style="background:#f8f9fb;border-radius:8px;padding:.8rem;margin-top:.6rem;font-size:.85rem;">
                            <p style="margin:0 0 .5rem;color:#5A6B82;">{{ __('Add this CNAME record at your DNS provider, then click Verify:') }}</p>
                            <code style="display:block;background:#0B2545;color:#fff;padding:.6rem;border-radius:6px;word-break:break-all;">
                                _acme-challenge.{{ $cert->domain }} CNAME {{ $cert->acme_dns_fulldomain }}
                            </code>
                            <button wire:click="verifyDns('{{ $cert->id }}')" wire:loading.attr="disabled" style="margin-top:.6rem;background:#17A2B8;color:#fff;border:none;border-radius:6px;padding:.4rem 1rem;font-size:.85rem;">
                                {{ __('Verify DNS') }}
                            </button>
                        </div>
                    @elseif ($cert->status === 'verified')
                        <div style="margin-top:.6rem;">
                            <button wire:click="issueCertificate('{{ $cert->id }}')" wire:loading.attr="disabled" style="background:#0B2545;color:#fff;border:none;border-radius:6px;padding:.5rem 1.2rem;font-size:.85rem;">
                                <span wire:loading.remove wire:target="issueCertificate('{{ $cert->id }}')">{{ __('Issue certificate') }}</span>
                                <span wire:loading wire:target="issueCertificate('{{ $cert->id }}')">{{ __('Issuing... this can take a minute.') }}</span>
                            </button>
                        </div>
                    @elseif ($cert->status === 'issued')
                        <p style="color:#5A6B82;font-size:.85rem;margin:.4rem 0;">
                            {{ __('Expires') }}: {{ \Carbon\Carbon::parse($cert->expires_at)->format('M j, Y') }}
                            &mdash; {{ __('renews automatically') }}
                        </p>
                        {{-- REAL FEATURE 2026-08-22, requested directly: real,
                             additional formats beyond PEM, for the wide range of
                             real servers/tools that expect something other than
                             PEM (cert.pem/chain.pem: Certbot's own real, separate
                             outputs, zero conversion; DER and PKCS12/PFX: real
                             openssl conversions, done at issuance time). --}}
                        <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:.4rem;">
                            <button wire:click="downloadFullchain('{{ $cert->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;padding:0;text-decoration:underline;">{{ __('Full chain (PEM)') }}</button>
                            <button wire:click="downloadCertOnly('{{ $cert->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;padding:0;text-decoration:underline;">{{ __('Certificate only (PEM)') }}</button>
                            <button wire:click="downloadChain('{{ $cert->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;padding:0;text-decoration:underline;">{{ __('Chain only (PEM)') }}</button>
                            <button wire:click="downloadPrivkey('{{ $cert->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;padding:0;text-decoration:underline;">{{ __('Private key (PEM)') }}</button>
                            <button wire:click="downloadDer('{{ $cert->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;padding:0;text-decoration:underline;">{{ __('Certificate (DER)') }}</button>
                            <button wire:click="downloadPfx('{{ $cert->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;padding:0;text-decoration:underline;">{{ __('PFX bundle (PKCS12)') }}</button>
                        </div>
                        @if ($cert->pfx_password)
                            <p style="color:#5A6B82;font-size:.78rem;margin-top:.4rem;">
                                {{ __('PFX password') }}: <code style="background:#f8f9fb;padding:.1rem .4rem;border-radius:4px;">{{ $cert->pfx_password }}</code>
                                &mdash; {{ __('you will need this to open the .pfx file') }}
                            </p>
                        @endif
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
