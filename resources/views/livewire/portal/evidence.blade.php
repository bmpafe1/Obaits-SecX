<div>
    <h1 style="color:#0B2545;">{{ __('Policies & documents') }}</h1>
    <p style="color:#5A6B82;">{{ __('The policies, procedures and records you maintain. Upload documents here to evidence controls that require written policy.') }}</p>
 
    @if (session('ev_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('ev_ok') }}</div>
    @endif
 
    @if (!$folderId)
        <div style="background:#fff3cd;padding:1rem;border-radius:8px;">{{ __('Your compliance workspace is still being set up. Please check back shortly.') }}</div>
    @endif
 
    @if ($folderId && \App\Support\Perm::can('manage_documents'))
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Upload evidence') }}</h3>
        <div style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap;">
            <input wire:model="name" placeholder="{{ __('e.g. Access Control Policy') }}" style="padding:.5rem;min-width:260px;">
            <input type="file" wire:model="file">
            <button wire:click="upload" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;">{{ __('Upload') }}</button>
        </div>
        @error('file') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        @error('name') <small style="color:#c0392b;">{{ $message }}</small> @enderror
    </div>
    @endif
 
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Your evidence') }}</h3>
        @if (empty($evidences))
            <p style="color:#5A6B82;">{{ __('No evidence uploaded yet.') }}</p>
        @else
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;color:#5A6B82;border-bottom:1px solid #eee;">
                        <th style="padding:.5rem;">{{ __('Name') }}</th>
                        <th style="padding:.5rem;">{{ __('Added') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($evidences as $ev)
                        <tr style="border-bottom:1px solid #f2f2f2;">
                            <td style="padding:.5rem;">{{ $ev['name'] ?? '—' }}</td>
                            <td style="padding:.5rem;">{{ isset($ev['created_at']) ? \Illuminate\Support\Str::limit($ev['created_at'], 10, '') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

