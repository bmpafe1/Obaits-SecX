<div style="font-family:system-ui,sans-serif; max-width:480px; margin:2rem auto; padding:2rem; background:#fff; border-radius:12px;">
    <h1 style="color:#1F3864;">{{ __('Start free trial') }}</h1>
 
    @if (session('ok'))
        <div style="background:#e6ffe6; padding:1rem; border-radius:6px; margin-bottom:1rem;">{{ session('ok') }}</div>
    @endif
 
    <form wire:submit="register" style="display:flex; flex-direction:column; gap:1rem;">
        <div><label>{{ __('First name') }}</label>
            <input wire:model="first_name" style="width:100%; padding:.5rem;">
            @error('first_name') <small style="color:#c0392b;">{{ $message }}</small> @enderror</div>
 
        <div><label>{{ __('Last name') }}</label>
            <input wire:model="last_name" style="width:100%; padding:.5rem;">
            @error('last_name') <small style="color:#c0392b;">{{ $message }}</small> @enderror</div>
 
        <div><label>{{ __('Professional email') }}</label>
            <input wire:model="email" type="email" style="width:100%; padding:.5rem;">
            @error('email') <small style="color:#c0392b;">{{ $message }}</small> @enderror</div>

        <div><label>{{ __('Phone') }}</label>
            <input wire:model="phone" placeholder="+237 6XX XXX XXX" style="width:100%; padding:.5rem;">
            <small style="color:#8fa3bf;">{{ __('Include your country code, starting with +') }}</small>
            @error('phone') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror</div>

        <div><label>{{ __('Country') }}</label>
            <select wire:model="country" style="width:100%; padding:.5rem;">
                <option value="">{{ __('Choose your country') }}</option>
                @foreach (\App\Support\Countries::list() as $code => $name)
                    <option value="{{ $code }}">{{ $name }}</option>
                @endforeach
            </select>
            @error('country') <small style="color:#c0392b;">{{ $message }}</small> @enderror</div>
 
        <div><label>{{ __('Organization name') }}</label>
            <input wire:model="organization_name" style="width:100%; padding:.5rem;">
            @error('organization_name') <small style="color:#c0392b;">{{ $message }}</small> @enderror</div>
 
        <div><label>{{ __('Organization website') }}</label>
            <input wire:model="organization_website" placeholder="www.example.com" style="width:100%; padding:.5rem;">
            @error('organization_website') <small style="color:#c0392b;">{{ $message }}</small> @enderror</div>
        <label style="display:flex; gap:.5rem; align-items:flex-start; font-size:.9rem; color:#555;">
            <input type="checkbox" wire:model="acknowledge_mismatch">
            <span>{{ __('My work email is on a different domain than our website. I confirm I am authorized to represent this organization.') }}</span>
        </label>
        <p style="font-size:.9rem; color:#555;">
            {{ __('After you verify your email, we\'ll send you a link to set your password.') }}
        </p>
        <button type="submit" style="background:#1F3864; color:#fff; padding:.8rem; border:none; border-radius:8px; font-size:1rem;">{{ __('Create my account') }}</button>
    </form>
</div>
