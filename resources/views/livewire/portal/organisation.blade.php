<div>
    <h1 style="color:#0B2545;">{{ __('Your organisation') }}</h1>
    <p style="color:#5A6B82;">{{ __('These details appear on the compliance documents we generate for your auditors.') }}</p>

    @if (session('org_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('org_ok') }}</div>
    @endif

    {{-- Completeness --}}
    <div style="background:#fff;border-radius:12px;padding:1.2rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        @php $col = $completeness >= 100 ? '#1e824c' : ($completeness >= 50 ? '#b8860b' : '#c0392b'); @endphp
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <strong style="color:{{ $col }};">{{ __('Ready for audit documents') }}: {{ $completeness }}%</strong>
        </div>
        <div style="background:#eee;height:8px;border-radius:4px;margin:.6rem 0;">
            <div style="background:{{ $col }};height:8px;border-radius:4px;width:{{ $completeness }}%;"></div>
        </div>
        @if (!empty($missing))
            <p style="color:#5A6B82;font-size:.9rem;margin:.4rem 0 0;">{{ __('Still needed before we can issue documents an auditor will accept:') }}</p>
            <ul style="color:#c0392b;font-size:.9rem;margin:.3rem 0 0;padding-left:1.1rem;">
                @foreach ($missing as $label)
                    <li>{{ __($label) }}</li>
                @endforeach
            </ul>
        @else
            <p style="color:#1e824c;font-size:.9rem;margin:.4rem 0 0;">{{ __('All set. We can generate documents your auditor will accept.') }}</p>
        @endif
    </div>

    @php $canEdit = \App\Support\Perm::can('manage_team'); @endphp

    {{-- Identity --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Who you are') }}</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.9rem;">
            <label>{{ __('Registered legal name') }}
                <small style="display:block;color:#5A6B82;">{{ __('Exactly as it appears on your company registration.') }}</small>
                <input wire:model="legal_name" @disabled(!$canEdit) style="width:100%;padding:.4rem;">
            </label>
            <label>{{ __('Trading name') }}
                <small style="display:block;color:#5A6B82;">{{ __('What your customers call you, if different.') }}</small>
                <input wire:model="trading_name" @disabled(!$canEdit) style="width:100%;padding:.4rem;">
            </label>
            <label>{{ __('Company registration number') }}
                <input wire:model="registration_number" @disabled(!$canEdit) style="width:100%;padding:.4rem;">
            </label>
            <label>{{ __('Industry / sector') }}
                <small style="display:block;color:#5A6B82;">{{ __('e.g. Microfinance, Healthcare, Retail.') }}</small>
                <input wire:model="sector" @disabled(!$canEdit) style="width:100%;padding:.4rem;">
            </label>
        </div>

        {{-- Logo --}}
        <div style="margin-top:1rem;">
            <label>{{ __('Logo') }}
                <small style="display:block;color:#5A6B82;">{{ __('Appears at the top of your documents. PNG or JPG, up to 2 MB.') }}</small>
            </label>
            @if ($logo_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::url($logo_path) }}" alt="logo" style="max-height:60px;margin:.5rem 0;">
            @endif
            @if ($canEdit)
                <input type="file" wire:model="logo" accept="image/png,image/jpeg" style="display:block;margin-top:.4rem;">
                @error('logo') <small style="color:#c0392b;">{{ $message }}</small> @enderror
                <div wire:loading wire:target="logo" style="color:#5A6B82;font-size:.85rem;">{{ __('Uploading…') }}</div>
            @endif
        </div>
    </div>

    {{-- Address + contact --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Where to find you') }}</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.9rem;">
            <label>{{ __('Registered address') }}<input wire:model="address_line1" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Address line 2') }}<input wire:model="address_line2" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
            <label>{{ __('City') }}<input wire:model="city" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Country') }}<input wire:model="country" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Phone') }}<input wire:model="phone" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Contact email') }}<input wire:model="contact_email" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Website') }}<input wire:model="website" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Approximate number of staff') }}<input type="number" wire:model="headcount" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
        </div>
    </div>

    {{-- Governance --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Who is responsible') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('Auditors will ask for a named person who owns information security.') }}
            {{ __('Who signs each individual document is set separately on the People and approvals page.') }}
            <a href="/portal/governance">{{ __('People and approvals') }}</a>
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.9rem;">
            <label>{{ __('Person responsible for information security') }}<input wire:model="security_officer_name" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Their job title') }}<input wire:model="security_officer_title" @disabled(!$canEdit) placeholder="{{ __('e.g. Head of IT') }}" style="width:100%;padding:.4rem;"></label>
        </div>
    </div>

    {{-- ISMS scope --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('What your security programme covers') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('This is the first thing an auditor reads. It says which parts of your organisation and which systems are covered — and what is deliberately left out. Not sure how to write it? Let Brian draft one for you.') }}
        </p>
        <textarea wire:model="isms_scope" rows="5" @disabled(!$canEdit) style="width:100%;padding:.5rem;"></textarea>
        @if ($canEdit)
            <button wire:click="draftScope" wire:loading.attr="disabled"
                style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.45rem 1rem;margin-top:.5rem;cursor:pointer;">
                {{ __('Ask Brian to draft this') }}
            </button>
            <span wire:loading wire:target="draftScope" style="color:#5A6B82;font-size:.85rem;margin-left:.5rem;">{{ __('Brian is drafting…') }}</span>
            <p style="color:#5A6B82;font-size:.8rem;margin-top:.4rem;">{{ __('Brian will suggest a draft. Read it, edit it, and make sure it matches reality before saving.') }}</p>
        @endif
    </div>

    {{-- Document control --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Document control') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">{{ __('Auditors expect every policy to carry a version, a date, and a review schedule.') }}</p>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.9rem;">
            <label>{{ __('Version') }}<input wire:model="doc_version" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Effective date') }}<input type="date" wire:model="effective_date" @disabled(!$canEdit) style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Review every') }}
                <select wire:model="review_cycle" @disabled(!$canEdit) style="width:100%;padding:.4rem;">
                    <option value="annual">{{ __('Year') }}</option>
                    <option value="biannual">{{ __('Six months') }}</option>
                    <option value="quarterly">{{ __('Quarter') }}</option>
                </select>
            </label>
        </div>
    </div>

    @if ($canEdit)
        <button wire:click="save" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.6rem 1.4rem;cursor:pointer;">{{ __('Save') }}</button>
    @else
        <p style="color:#5A6B82;">{{ __('Only an administrator can change these details.') }}</p>
    @endif
</div>