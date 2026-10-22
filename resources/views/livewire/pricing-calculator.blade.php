{{--
    Shared pricing calculator.

    RESTYLED 2026-08-29 for the dark SecX landing page. The markup carries no
    colours of its own -- it inherits from whatever section contains it, using
    the CSS variables the landing page defines. That way this component can be
    reused elsewhere without dragging a white card onto a dark background, which
    is exactly how it looked before.

    Prices live in PricingCalculator::$perAssetMonthly, not here.
--}}
<div style="display:grid;grid-template-columns:320px 1fr;gap:30px;align-items:start;">

    <div style="background:var(--p,#0d1d30);border:1px solid var(--line,#284965);padding:24px;">
        <label for="assets" style="display:block;font-size:14.5px;font-weight:600;margin-bottom:9px;color:var(--txt,#edf6ff);">
            {{ __('How many devices do you want to protect?') }}
        </label>
        <input id="assets" type="number" min="1" wire:model.live="assets"
               style="width:100%;font-family:'IBM Plex Mono',monospace;font-size:21px;padding:11px 13px;
                      border:1px solid var(--line,#284965);border-radius:4px;
                      background:var(--bg,#071321);color:var(--txt,#edf6ff);">
        <p style="font-size:12.5px;color:var(--muted,#a8bdd2);margin:9px 0 0;line-height:1.5;">
            {{ __('A device is anything you enrol — a laptop, a server or a virtual machine. You can add more later without starting again.') }}
        </p>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div style="background:var(--p,#0d1d30);border:1px solid var(--line,#284965);padding:22px;">
            <p style="font-family:'IBM Plex Mono',monospace;font-size:10.5px;letter-spacing:.16em;text-transform:uppercase;color:var(--muted,#a8bdd2);margin:0 0 7px;">
                {{ __('Monthly') }}
            </p>
            <p style="font-family:Archivo,sans-serif;font-weight:800;font-size:34px;letter-spacing:-.04em;line-height:1;margin:0;color:var(--txt,#edf6ff);">
                ${{ $monthly }}
            </p>
            <p style="font-size:12.5px;color:var(--muted,#a8bdd2);margin:7px 0 0;">
                {{ __('per month') }}
            </p>
        </div>

        <div style="background:var(--p,#0d1d30);border:1px solid var(--blue,#2e8cff);padding:22px;">
            <p style="font-family:'IBM Plex Mono',monospace;font-size:10.5px;letter-spacing:.16em;text-transform:uppercase;color:var(--muted,#a8bdd2);margin:0 0 7px;">
                {{ __('Yearly') }}
            </p>
            <p style="font-family:Archivo,sans-serif;font-weight:800;font-size:34px;letter-spacing:-.04em;line-height:1;margin:0;color:var(--txt,#edf6ff);">
                ${{ $yearly }}
            </p>
            <p style="font-size:12.5px;color:var(--muted,#a8bdd2);margin:7px 0 0;">
                {{ __('per year') }}
            </p>
            <span style="display:inline-block;margin-top:10px;font-family:'IBM Plex Mono',monospace;font-size:10.5px;letter-spacing:.11em;text-transform:uppercase;color:var(--green,#50d28a);">
                {{ __('Two months free') }}
            </span>
        </div>
    </div>
</div>

<style>
@media(max-width:820px){
    .sx [wire\:id] > div{grid-template-columns:1fr !important;gap:20px !important}
}
</style>
