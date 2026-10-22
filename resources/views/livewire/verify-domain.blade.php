<div style="font-family:system-ui,sans-serif; max-width:560px; margin:3rem auto; padding:2rem; background:#fff; border-radius:12px;">
    <h1 style="color:#1F3864;">Verify your domain</h1>
    <p>To prove you control <strong>{{ $tenant->primary_domain }}</strong>, add this DNS TXT record:</p>
    <div style="background:#f2f2f2; padding:1rem; border-radius:6px; font-family:monospace; word-break:break-all;">
        <div><strong>Host:</strong> _obaits-challenge.{{ $tenant->primary_domain }}</div>
        <div><strong>Value:</strong> {{ $tenant->domain_verify_token }}</div>
    </div>
    <p style="margin-top:1rem;">Then click below. DNS can take a few minutes to propagate.</p>
    <form wire:submit="check">
        @error('domain') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        <button type="submit" style="background:#1F3864; color:#fff; padding:.8rem 1.5rem; border:none; border-radius:8px; margin-top:1rem;">I've added the record</button>
    </form>
</div>

