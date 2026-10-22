{{--
    ISO download stub.

    The on-premise appliance is not built yet, but the landing page links here
    from the top menu and the deployment section. A 404 on a menu link is worse
    than a page that says plainly what is coming -- and a visitor who wants the
    ISO is exactly the visitor worth hearing from.
--}}
<x-layouts.app>
<div class="sx" style="min-height:100vh;display:flex;align-items:center">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=IBM+Plex+Mono:wght@400&family=IBM+Plex+Sans:wght@400;600&display=swap" rel="stylesheet">
  <style>
    .sx{--bg:#071321;--p:#0d1d30;--line:#284965;--txt:#edf6ff;--muted:#a8bdd2;--blue:#2e8cff;--cyan:#35c5f4;--gold:#ffc866;
      color:var(--txt);background:radial-gradient(circle at 15% -10%,#103a63,var(--bg) 45%);
      font-family:"IBM Plex Sans",system-ui,sans-serif;line-height:1.62;width:100%}
    .sx *{box-sizing:border-box}
    .sx .wrap{max-width:620px;margin:0 auto;padding:60px 26px}
    .sx h1{font-family:Archivo;font-weight:800;font-size:clamp(28px,4.5vw,44px);letter-spacing:-.04em;line-height:1.05;margin:0 0 18px}
    .sx .eyebrow{font-family:"IBM Plex Mono";font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--gold);margin:0 0 18px}
    .sx p{color:var(--muted);font-size:17px;margin:0 0 16px}
    .sx ul{color:var(--muted);font-size:15.5px;padding-left:18px;margin:0 0 24px}
    .sx li{margin-bottom:7px}
    .sx .row{display:flex;gap:11px;flex-wrap:wrap;margin-top:28px}
    .sx a.btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:999px;padding:12px 22px;background:var(--p);color:#fff;text-decoration:none;font-weight:600;font-size:15px}
    .sx a.btn:hover{border-color:var(--blue)}
    .sx a.solid{background:var(--blue);border-color:var(--blue);color:#04121f;font-weight:700}
    .sx a.back{color:var(--cyan);text-decoration:none;font-size:14px;display:inline-block;margin-bottom:26px}
  </style>
  <div class="wrap">
    <a class="back" href="/">&larr; {{ __('Back to SecX') }}</a>
    <p class="eyebrow">{{ __('On-premise appliance') }}</p>
    <h1>{{ __('The ISO is not ready to download yet.') }}</h1>
    <p>{{ __('We are building the installable appliance now. It runs the same platform as the hosted service — the same capabilities, on your own hardware, with your data staying on your network.') }}</p>
    <p>{{ __('When it is ready it will:') }}</p>
    <ul>
      <li>{{ __('Install onto your own server from a single image') }}</li>
      <li>{{ __('Keep every piece of your data on your premises') }}</li>
      <li>{{ __('Work where connectivity is unreliable') }}</li>
      <li>{{ __('Synchronise the marketplace and training catalogues daily') }}</li>
    </ul>
    <p>{{ __('Tell us you want it and we will let you know the day it is available — and ask what your environment needs, which genuinely shapes what we build.') }}</p>
    <div class="row">
      <a class="btn solid" href="mailto:hello@obaits.com?subject=SecX%20on-premise%20ISO">{{ __('Tell us you want it') }}</a>
      <a class="btn" href="/register">{{ __('Start on the hosted version') }}</a>
    </div>
  </div>
</div>
</x-layouts.app>
