<div style="font-family:system-ui,sans-serif; max-width:420px; margin:3rem auto; padding:2rem; background:#fff; border-radius:12px;">
    <h1 style="color:#1F3864;">Check your email</h1>
    <p>Enter the 6-digit code we sent you.</p>
    <form wire:submit="verify" style="display:flex; flex-direction:column; gap:1rem;">
        <input wire:model="code" maxlength="6" style="font-size:1.4rem; text-align:center; letter-spacing:.3rem; padding:.6rem;">
        @error('code') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        <button type="submit" style="background:#1F3864; color:#fff; padding:.8rem; border:none; border-radius:8px;">Verify</button>
    </form>
</div>

