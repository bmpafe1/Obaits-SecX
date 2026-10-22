<div style="max-width:620px;">
    <h1 style="font-size:1.5rem;color:#1F3864;margin-bottom:.4rem;">{{ __('Tell us about your business') }}</h1>
    <p style="color:#5A6B82;line-height:1.6;margin-bottom:1.4rem;">
        {{ __('A few quick questions, asked once. This helps us tailor your security posture and lets providers give you a useful answer if you ever request help.') }}
    </p>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">

        <label style="display:block;color:#5A6B82;font-size:.9rem;margin-bottom:.25rem;">{{ __('What sector are you in?') }}</label>
        <select wire:model.live="sector" style="width:100%;padding:.6rem;border:1px solid #dde3ea;border-radius:8px;">
            <option value="">{{ __('Choose one') }}</option>
            @foreach ($sectors as $key => $label)
                <option value="{{ $key }}">{{ __($label) }}</option>
            @endforeach
        </select>
        @error('sector') <small style="color:#c0392b;">{{ $message }}</small> @enderror

        @if ($sector === 'other')
            <input type="text" wire:model="sectorOther" placeholder="{{ __('Tell us what you do') }}"
                   style="width:100%;padding:.6rem;border:1px solid #dde3ea;border-radius:8px;margin-top:.5rem;">
            @error('sectorOther') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        @endif

        <label style="display:block;color:#5A6B82;font-size:.9rem;margin:1rem 0 .25rem;">{{ __('How many people work there?') }}</label>
        <select wire:model="headcount" style="width:100%;padding:.6rem;border:1px solid #dde3ea;border-radius:8px;">
            <option value="">{{ __('Choose one') }}</option>
            @foreach ($headcounts as $key => $label)
                <option value="{{ $key }}">{{ __($label) }}</option>
            @endforeach
        </select>
        @error('headcount') <small style="color:#c0392b;">{{ $message }}</small> @enderror

        <label style="display:block;color:#5A6B82;font-size:.9rem;margin:1rem 0 .25rem;">{{ __('In a sentence or two, what does your business do?') }}</label>
        <textarea wire:model="description" rows="4"
                  placeholder="{{ __('For example: we run a regional logistics company moving freight between Douala and N\'Djamena, with a fleet of 40 vehicles and three depots.') }}"
                  style="width:100%;padding:.6rem;border:1px solid #dde3ea;border-radius:8px;"></textarea>
        <small style="color:#8fa3bf;">{{ __('At least a full sentence, please — it is what a provider reads first.') }}</small>
        @error('description') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror

        <button wire:click="save" wire:loading.attr="disabled"
                style="width:100%;background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.7rem;font-size:.95rem;cursor:pointer;margin-top:1.2rem;">
            <span wire:loading.remove wire:target="save">{{ __('Continue to my dashboard') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
        </button>
    </div>
</div>
