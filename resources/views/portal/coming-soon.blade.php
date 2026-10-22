{{-- Shared placeholder for navigation areas that exist in the information
     architecture but have no implementation yet. Stubbed deliberately: adding a
     top-level menu area later disrupts the menu again, and a link that 404s is
     worse than one that says plainly what is coming. --}}
<x-layouts.portal>
    <div style="max-width:640px;">
        <h1 style="font-size:1.6rem; color:#1F3864; margin-bottom:.6rem;">{{ __($title) }}</h1>
        <div style="background:#fff; border-radius:12px; padding:1.5rem; box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <p style="color:#5A6B82; line-height:1.6; margin:0;">{{ __($body) }}</p>
        </div>
    </div>
</x-layouts.portal>
