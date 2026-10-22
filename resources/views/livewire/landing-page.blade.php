{{--
    SecX public landing page.

    RENAMED 2026-08-29: this page is SecX, a product of Obaits. The portal after
    login still says Obaits and is renamed separately -- a deliberate first step.

    ALL CSS IS SCOPED UNDER .sx. This layout is shared with register, verify-email
    and mfa-help, and unscoped body/.btn rules would leak into them.

    Pricing uses the existing PricingCalculator component, not a reimplementation:
    price changes belong in its $perAssetMonthly, not duplicated in a template.
--}}
<div class="sx">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
.sx{--bg:#071321;--p:#0d1d30;--p2:#102840;--line:#284965;--txt:#edf6ff;--muted:#a8bdd2;--blue:#2e8cff;--cyan:#35c5f4;--green:#50d28a;--gold:#ffc866;
  color:var(--txt);background:radial-gradient(circle at 15% -10%,#103a63,var(--bg) 45%);background-attachment:fixed;
  font-family:"IBM Plex Sans",system-ui,sans-serif;font-size:16px;line-height:1.62;-webkit-font-smoothing:antialiased;min-height:100vh}
.sx *{box-sizing:border-box}
@media (prefers-reduced-motion:reduce){.sx *{transition:none!important;animation:none!important}}
.sx h1,.sx h2,.sx h3{font-family:Archivo,system-ui,sans-serif;font-weight:800;letter-spacing:-.045em;line-height:1.03;margin:0}
.sx a{color:var(--txt)}
.sx .wrap{max-width:1180px;margin:0 auto;padding:0 26px}
.sx .eyebrow{font-family:"IBM Plex Mono",monospace;font-size:11px;font-weight:500;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:12px;margin-bottom:20px}
.sx .eyebrow::after{content:"";flex:1;height:1px;background:var(--line)}
.sx header{position:sticky;top:0;z-index:60;background:rgba(7,19,33,.85);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
.sx .bar{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:14px 0}
.sx .mark{font-family:Archivo;font-weight:800;font-size:19px;letter-spacing:-.03em;text-decoration:none;display:flex;align-items:baseline;gap:8px}
.sx .mark small{font-family:"IBM Plex Mono";font-size:9.5px;font-weight:400;letter-spacing:.16em;color:var(--muted);text-transform:uppercase}
.sx nav{display:flex;align-items:center;gap:18px}
.sx nav a{font-size:14px;text-decoration:none;color:#cfe1ef;display:inline-flex;align-items:center;gap:7px}
.sx nav a:hover{color:var(--cyan)}
.sx .btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:999px;padding:10px 18px;background:var(--p);color:#fff;text-decoration:none;font-weight:600;font-size:14px;transition:border-color .16s,background .16s}
.sx .btn:hover{border-color:var(--blue);background:var(--p2);color:#fff}
.sx .btn-solid{background:var(--blue);border-color:var(--blue);color:#04121f;font-weight:700}
.sx .btn-solid:hover{background:var(--cyan);border-color:var(--cyan);color:#04121f}
.sx .btn-lg{padding:13px 26px;font-size:15.5px}
@media(max-width:1040px){.sx nav a.hide-md{display:none}}
@media(max-width:760px){.sx nav a:not(.btn-solid){display:none}}
.sx .hero{padding:64px 0 0}
.sx .hero h1{font-size:clamp(34px,5.4vw,64px);max-width:18ch}
.sx .hero h1 em{font-style:normal;color:var(--gold)}
.sx .lede{font-size:clamp(16px,1.8vw,19px);color:var(--muted);max-width:58ch;margin:22px 0 0}
.sx .row{display:flex;gap:11px;flex-wrap:wrap;margin-top:26px}
.sx .reassure{font-family:"IBM Plex Mono";font-size:11.5px;letter-spacing:.05em;color:var(--muted);margin-top:15px}
.sx .chain{margin:54px 0 0;padding:24px 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.sx .chain-inner{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--line);border:1px solid var(--line)}
.sx .link{background:var(--p);padding:13px 12px;border-left:3px solid var(--blue)}
.sx .link b{display:block;font-family:Archivo;font-weight:700;font-size:14px;margin-bottom:3px;letter-spacing:-.02em}
.sx .link span{font-size:12px;color:var(--muted);line-height:1.45;display:block}
@media(max-width:1000px){.sx .chain-inner{grid-template-columns:repeat(3,1fr)}}
@media(max-width:620px){.sx .chain-inner{grid-template-columns:1fr}}
.sx section{padding:76px 0}
.sx .rule{border-top:1px solid var(--line)}
.sx h2{font-size:clamp(25px,3.4vw,38px);max-width:20ch}
.sx .section-lede{font-size:17px;color:var(--muted);max-width:62ch;margin:18px 0 0}
.sx .feat{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;margin-top:38px;background:var(--line);border:1px solid var(--line)}
.sx .feat > div{background:var(--bg);padding:20px 22px}
.sx .feat h3{font-size:15px;font-weight:600;margin:0 0 5px;font-family:"IBM Plex Sans";letter-spacing:0}
.sx .feat p{margin:0;font-size:14px;color:var(--muted);line-height:1.55}
.sx .feat .k{font-family:"IBM Plex Mono";font-size:10px;letter-spacing:.15em;text-transform:uppercase;color:var(--cyan);display:block;margin-bottom:8px}
@media(max-width:900px){.sx .feat{grid-template-columns:1fr}}
.sx .pills{display:flex;flex-wrap:wrap;gap:6px;margin-top:26px}
.sx .pill{display:inline-block;background:#15324c;border-radius:99px;padding:5px 11px;font-size:11.5px;color:#d8eafa;font-family:"IBM Plex Mono"}
.sx .deploy{display:grid;grid-template-columns:1fr 1fr;gap:1px;margin-top:40px;background:var(--line);border:1px solid var(--line)}
.sx .deploy > div{background:var(--p);padding:28px 26px}
.sx .deploy h3{font-size:19px;margin-bottom:6px}
.sx .deploy .kicker{font-family:"IBM Plex Mono";font-size:10.5px;letter-spacing:.16em;text-transform:uppercase;color:var(--gold);margin-bottom:12px}
.sx .deploy ul{margin:14px 0 0;padding-left:17px;color:var(--muted);font-size:14.5px}
.sx .deploy li{margin-bottom:6px}
@media(max-width:760px){.sx .deploy{grid-template-columns:1fr}}
.sx .buy-note{font-size:13px;color:var(--muted);margin-top:16px;max-width:60ch}
.sx .cta{padding:74px 0;text-align:center;border-top:1px solid var(--line)}
.sx .cta h2{margin:0 auto;max-width:22ch}
.sx .cta .row{justify-content:center}
.sx footer{border-top:1px solid var(--line);padding:34px 0;font-size:13.5px;color:var(--muted)}
.sx .f-row{display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap;align-items:center}
.sx footer a{color:#cfe1ef;text-decoration:none}
.sx footer a:hover{color:var(--cyan)}
{{-- The shared PricingCalculator component, restyled for this page only. --}}
.sx .pc-wrap{margin-top:38px}
.sx .pc-wrap input[type=number]:focus{outline:2px solid var(--cyan);outline-offset:1px}
</style>

<header>
  <div class="wrap bar">
    <a class="mark" href="/">SecX <small>{{ __('by Obaits') }}</small></a>
    <nav>
      <a href="#what">{{ __('What it does') }}</a>
      <a href="#pricing">{{ __('Pricing') }}</a>
      <a href="#deployment" class="hide-md">{{ __('Deployment') }}</a>
      <a href="https://github.com/bmpafe1/Obaits-SecX" rel="noopener"><svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0a8 8 0 0 0-2.53 15.59c.4.07.55-.17.55-.38l-.01-1.34c-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82a7.4 7.4 0 0 1 4 0c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48l-.01 2.19c0 .21.15.46.55.38A8 8 0 0 0 8 0Z"/></svg>GitHub</a>
      <a class="btn" href="/download">{{ __('Download ISO') }}</a>
      <a href="/login">{{ __('Log in') }}</a>
      <a class="btn btn-solid" href="/register">{{ __('Start free trial') }}</a>
    </nav>
  </div>
</header>

<div class="wrap hero">
  <p class="eyebrow">{{ __('All-in-one security platform') }}</p>
  <h1>{{ __('Secure your business.') }} <em>{{ __('Pass your audit.') }}</em></h1>
  <p class="lede">{{ __('SecX brings endpoint protection, threat detection, vulnerability management, incident response and compliance into one platform — built for teams that do not have a security specialist for every tool.') }}</p>
  <div class="row">
    <a class="btn btn-solid btn-lg" href="/register">{{ __('Start free trial') }}</a>
    <a class="btn btn-lg" href="#what">{{ __('See what it does') }}</a>
  </div>
  <p class="reassure">{{ __('30-day trial · 10 devices · No card required · English & French') }}</p>
</div>

<div class="wrap">
  <div class="chain">
    <div class="chain-inner">
      <div class="link"><b>{{ __('Identify') }}</b><span>{{ __('Every device, its software and its owner') }}</span></div>
      <div class="link"><b>{{ __('Protect') }}</b><span>{{ __('Policies, firewall rules, threat blocking') }}</span></div>
      <div class="link"><b>{{ __('Detect') }}</b><span>{{ __('File integrity, rootkits, log analysis') }}</span></div>
      <div class="link"><b>{{ __('Prioritise') }}</b><span>{{ __('What is actually being exploited') }}</span></div>
      <div class="link"><b>{{ __('Respond') }}</b><span>{{ __('Cases, evidence, timelines') }}</span></div>
      <div class="link"><b>{{ __('Comply') }}</b><span>{{ __('Controls, policies, audit evidence') }}</span></div>
      <div class="link"><b>{{ __('Recover') }}</b><span>{{ __('Backups you have actually tested') }}</span></div>
    </div>
  </div>
</div>

<section id="what">
  <div class="wrap">
    <p class="eyebrow">{{ __('What it does') }}</p>
    <h2>{{ __('One agent. One console. Every question answered in one place.') }}</h2>
    <p class="section-lede">{{ __('Most organisations end up with security spread across half a dozen products that never speak to each other — and nobody able to answer something as simple as: is this device patched, monitored, backed up and compliant?') }}</p>
    <div class="feat">
      <div><span class="k">{{ __('Endpoints') }}</span><h3>{{ __('Know every device you own') }}</h3><p>{{ __('Windows, Linux and macOS enrolled with one script. Live hardware, software and network inventory that keeps itself current.') }}</p></div>
      <div><span class="k">{{ __('Endpoints') }}</span><h3>{{ __('Enforce without breaking things') }}</h3><p>{{ __('Firewall, update, port and website policies run in audit mode first, so you see exactly what would change before it changes.') }}</p></div>
      <div><span class="k">{{ __('Detection') }}</span><h3>{{ __('Catch what matters') }}</h3><p>{{ __('File-integrity monitoring, rootkit detection, log analysis and CIS baselines, with alerts written in plain language.') }}</p></div>
      <div><span class="k">{{ __('Intelligence') }}</span><h3>{{ __('Block known-bad traffic') }}</h3><p>{{ __('Four independent threat feeds refreshed hourly on every device, with health reporting so a device that stops protecting itself is noticed.') }}</p></div>
      <div><span class="k">{{ __('Vulnerabilities') }}</span><h3>{{ __('Fix what is being exploited') }}</h3><p>{{ __('Network and authenticated web scanning ranked by real-world exploitation using CISA KEV and EPSS — not severity scores alone.') }}</p></div>
      <div><span class="k">{{ __('Vulnerabilities') }}</span><h3>{{ __('Scan inside your network') }}</h3><p>{{ __('Internal machines are reachable over your own private network, so you never expose them to the internet to have them scanned.') }}</p></div>
      <div><span class="k">{{ __('Response') }}</span><h3>{{ __('Turn an alert into an investigation') }}</h3><p>{{ __('Any alert becomes a structured case with evidence, timeline, tasks and an audit trail your insurer or regulator can read.') }}</p></div>
      <div><span class="k">{{ __('Response') }}</span><h3>{{ __('A helpdesk that is also your incident register') }}</h3><p>{{ __('Tickets numbered per organisation, with security tickets flagged into a register ordered by detection date — the date auditors ask about.') }}</p></div>
      <div><span class="k">{{ __('Compliance') }}</span><h3>{{ __('End with documents, not intentions') }}</h3><p>{{ __('Framework assessment, generated policies, evidence linked to controls, and delegate packs for auditors and assessors.') }}</p></div>
      <div><span class="k">{{ __('Continuity') }}</span><h3>{{ __('Prove you can recover') }}</h3><p>{{ __('Encrypted scheduled backups with restore testing tracked. A backup that has never been restored is not a backup.') }}</p></div>
      <div><span class="k">{{ __('Operations') }}</span><h3>{{ __('Watch the infrastructure too') }}</h3><p>{{ __('Availability, resource use and service health alongside the security view — plus certificate expiry, an avoidable cause of outages.') }}</p></div>
      <div><span class="k">{{ __('Assistance') }}</span><h3>{{ __('Ask what an alert means') }}</h3><p>{{ __('An assistant grounded in your own environment, in English or French, that strips identifying details before any external call.') }}</p></div>
    </div>
    <p class="eyebrow" style="margin-top:46px">{{ __('Help when you need a person') }}</p>
    <p class="section-lede" style="margin-top:0">{{ __('SecX includes a marketplace of vetted providers who work in your country, and a curated training catalogue filtered to the languages your team reads. You share only what you choose to — your alerts, findings and inventory are never disclosed to a provider.') }}</p>
  </div>
</section>

<section class="rule" id="pricing">
  <div class="wrap">
    <p class="eyebrow">{{ __('Pricing') }}</p>
    <h2>{{ __('Priced per device. Nothing else to work out.') }}</h2>
    <p class="section-lede">{{ __('One price covers every capability. There is no security tier, no compliance add-on and no per-module licence — the platform you trial is the platform you keep.') }}</p>
    <div class="pc-wrap"><livewire:pricing-calculator /></div>
    <p class="buy-note">{{ __('Start with a 30-day trial on up to 10 devices — no card needed. Choose and pay for your plan from Billing in the portal whenever you are ready.') }}</p>
    <div class="row"><a class="btn btn-solid" href="/register">{{ __('Start free trial') }}</a></div>
  </div>
</section>

<section class="rule" id="deployment">
  <div class="wrap">
    <p class="eyebrow">{{ __('How you run it') }}</p>
    <h2>{{ __('Hosted by us, or entirely on your own hardware.') }}</h2>
    <p class="section-lede">{{ __('The same platform either way. There is no cut-down on-premise edition and nothing held back for the hosted service.') }}</p>
    <div class="deploy">
      <div>
        <p class="kicker">{{ __('Option A') }}</p><h3>{{ __('Hosted') }}</h3>
        <ul><li>{{ __('Nothing to install or maintain') }}</li><li>{{ __('Updates managed continuously') }}</li><li>{{ __('Running the same day you sign up') }}</li></ul>
      </div>
      <div>
        <p class="kicker">{{ __('Option B') }}</p><h3>{{ __('On-premise appliance') }}</h3>
        <ul><li>{{ __('Installs from an ISO onto your hardware') }}</li><li>{{ __('Your data never leaves your network') }}</li><li>{{ __('Works where connectivity is unreliable') }}</li><li>{{ __('Marketplace and training synchronised daily') }}</li></ul>
        <div class="row" style="margin-top:18px"><a class="btn" href="/download">{{ __('Download the ISO') }}</a></div>
      </div>
    </div>
    <p class="eyebrow" style="margin-top:48px">{{ __('Built on engines you can audit') }}</p>
    <p class="section-lede" style="margin-top:0">{{ __('SecX does not reinvent twenty years of security engineering. It integrates open-source engines that security teams already trust, and supplies the identity, tenant isolation, automation and interface they have never had.') }}</p>
    <div class="pills">
      <span class="pill">Wazuh</span><span class="pill">Fleet · osquery</span><span class="pill">OpenVAS · Greenbone</span><span class="pill">OWASP ZAP</span><span class="pill">DFIR-IRIS</span><span class="pill">Zabbix</span><span class="pill">CISO Assistant</span><span class="pill">lightNVR</span><span class="pill">Keycloak</span><span class="pill">Headscale</span>
    </div>
    <div class="row"><a class="btn" href="https://github.com/bmpafe1/Obaits-SecX">{{ __('View the source on GitHub') }}</a></div>
  </div>
</section>

<section class="cta">
  <div class="wrap">
    <h2>{{ __('Thirty days. Your own devices. No card.') }}</h2>
    <p class="lede" style="margin:16px auto 0;text-align:center">{{ __('Enrol a machine, run a scan and see your own findings before you decide anything.') }}</p>
    <div class="row">
      <a class="btn btn-solid btn-lg" href="/register">{{ __('Start free trial') }}</a>
      <a class="btn btn-lg" href="/login">{{ __('Log in') }}</a>
    </div>
  </div>
</section>

<footer>
  <div class="wrap f-row">
    <span>&copy; {{ date('Y') }} SecX — {{ __('a product of Obaits, by') }} <a href="https://www.aicexperts.com">AICexperts</a>.</span>
    <span><a href="/login">{{ __('Log in') }}</a> · <a href="/register">{{ __('Start free trial') }}</a> · <a href="/download">{{ __('Download ISO') }}</a> · <a href="https://github.com/bmpafe1/Obaits-SecX">GitHub</a></span>
  </div>
</footer>
</div>
