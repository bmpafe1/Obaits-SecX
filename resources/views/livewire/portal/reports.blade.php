<div class="rpt">
<style>
  .rpt .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1rem;margin-bottom:1.6rem}
  .rpt .card{background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08)}
  .rpt .card a{text-decoration:none;color:inherit;display:block}
  .rpt .k{font-size:.74rem;letter-spacing:.13em;text-transform:uppercase;color:#8fa3bf;margin:0 0 .5rem}
  .rpt .v{font-size:1.9rem;font-weight:600;color:#1F3864;line-height:1;margin:0}
  .rpt .s{font-size:.83rem;color:#5A6B82;margin:.45rem 0 0}
  .rpt h2{font-size:1.05rem;color:#1F3864;margin:1.8rem 0 .8rem}
  .rpt .sev{display:flex;gap:1.4rem;flex-wrap:wrap}
  .rpt .sev div{flex:1;min-width:90px}
  @media print{
    /* The portal navigation is outside this component, so reach it explicitly.
       A printed board pack should not carry a sidebar. */
    .portal-nav{display:none !important}
    body, main{margin:0 !important;padding:0 !important;width:100% !important}
    .rpt .card{box-shadow:none;border:1px solid #dde3ea;break-inside:avoid}
    .rpt .noprint{display:none}
    .rpt a{color:#1F3864 !important}
  }
</style>

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
    <div>
      <h1 style="font-size:1.6rem;color:#1F3864;margin-bottom:.3rem;">{{ __('Security posture') }}</h1>
      <p style="color:#5A6B82;margin:0;">
        {{ $tenant->organization_name ?? '' }} · {{ __('as at :date', ['date' => $generated->toFormattedDateString()]) }}
      </p>
    </div>
    <button onclick="window.print()" class="noprint"
            style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.55rem 1.2rem;cursor:pointer;font-size:.9rem;">
      {{ __('Print or save as PDF') }}
    </button>
  </div>

  <p style="color:#8fa3bf;font-size:.85rem;margin:.8rem 0 1.6rem;line-height:1.5;" class="noprint">
    {{ __('A summary of what the platform currently knows. Every figure links to the area that owns it.') }}
  </p>

  <h2>{{ __('Estate') }}</h2>
  <div class="grid">
    <div class="card"><a href="/portal/devices">
      <p class="k">{{ __('Devices enrolled') }}</p>
      <p class="v">{{ $devices }}@if ($limit)<span style="font-size:1rem;color:#8fa3bf;font-weight:400;"> / {{ $limit }}</span>@endif</p>
      <p class="s">{{ $limit && $devices >= $limit ? __('At your limit') : __('Monitored endpoints') }}</p>
    </a></div>
    <div class="card"><a href="/portal/team">
      <p class="k">{{ __('Team members') }}</p>
      <p class="v">{{ $users }}</p>
      <p class="s">{{ __('People with access') }}</p>
    </a></div>
    <div class="card"><a href="/portal/policies">
      <p class="k">{{ __('Policies applied') }}</p>
      <p class="v" style="color:{{ $policies === 0 ? '#8B2F2F' : '#1F3864' }};">{{ $policies }}</p>
      <p class="s">{{ $policies === 0 ? __('No policies enforced yet') : __('Group policy assignments') }}</p>
    </a></div>
  </div>

  <h2>{{ __('Vulnerabilities') }}</h2>
  <div class="grid">
    <div class="card" style="grid-column:span 2;"><a href="/portal/findings">
      <p class="k">{{ __('Open findings') }}</p>
      <div class="sev" style="margin-top:.3rem;">
        <div><p class="v" style="color:{{ $findings['high'] > 0 ? '#8B2F2F' : '#1F3864' }};">{{ $findings['high'] }}</p><p class="s">{{ __('High') }}</p></div>
        <div><p class="v" style="color:{{ $findings['medium'] > 0 ? '#7A5C00' : '#1F3864' }};">{{ $findings['medium'] }}</p><p class="s">{{ __('Medium') }}</p></div>
        <div><p class="v">{{ $findings['low'] }}</p><p class="s">{{ __('Low') }}</p></div>
        <div><p class="v" style="color:#8fa3bf;">{{ $findings['info'] }}</p><p class="s">{{ __('Informational') }}</p></div>
      </div>
    </a></div>
    <div class="card"><a href="/portal/scans">
      <p class="k">{{ __('Scans run') }}</p>
      <p class="v">{{ $scans }}</p>
      <p class="s">
        @if ($lastScan)
          {{ __('Last :when', ['when' => $lastScan->diffForHumans()]) }}
        @else
          <span style="color:#8B2F2F;">{{ __('Never scanned') }}</span>
        @endif
      </p>
    </a></div>
  </div>

  <h2>{{ __('Response and continuity') }}</h2>
  <div class="grid">
    <div class="card"><a href="/portal/helpdesk">
      <p class="k">{{ __('Open incidents') }}</p>
      <p class="v" style="color:{{ $incidents > 0 ? '#8B2F2F' : '#1F3864' }};">{{ $incidents }}</p>
      <p class="s">{{ __(':n ticket(s) in total', ['n' => $tickets]) }}</p>
    </a></div>
    <div class="card"><a href="/portal/backups">
      <p class="k">{{ __('Backup selections') }}</p>
      <p class="v" style="color:{{ $backups === 0 ? '#8B2F2F' : '#1F3864' }};">{{ $backups }}</p>
      <p class="s">{{ $backups === 0 ? __('Nothing is being backed up') : __('Devices with backups configured') }}</p>
    </a></div>
  </div>

  <h2>{{ __('Governance') }}</h2>
  <div class="grid">
    <div class="card"><a href="/portal/compliance">
      <p class="k">{{ __('Frameworks') }}</p>
      <p class="v" style="color:{{ $frameworks === 0 ? '#8B2F2F' : '#1F3864' }};">{{ $frameworks }}</p>
      <p class="s">{{ $frameworks === 0 ? __('No framework activated') : __('Compliance frameworks in use') }}</p>
    </a></div>
    <div class="card"><a href="/portal/document">
      <p class="k">{{ __('Documents') }}</p>
      <p class="v">{{ $documents }}</p>
      <p class="s">{{ __('Policies and procedures') }}</p>
    </a></div>
    <div class="card">
      <p class="k">{{ __('Security officer') }}</p>
      <p class="v" style="font-size:1.2rem;color:{{ empty($profile->security_officer_name) ? '#8B2F2F' : '#0B6E4F' }};">
        {{ empty($profile->security_officer_name) ? __('Not named') : __('Named') }}
      </p>
      <p class="s">{{ empty($profile->security_officer_name) ? __('Most frameworks expect one') : $profile->security_officer_title }}</p>
    </div>
  </div>

  <p style="color:#8fa3bf;font-size:.8rem;margin-top:2rem;line-height:1.5;">
    {{ __('Generated :when from your own data. This is a summary, not an assessment — each area holds the detail behind these figures.', ['when' => $generated->toDayDateTimeString()]) }}
  </p>
</div>
