{{-- A printable invoice.

     Deliberately plain HTML with print CSS rather than a server-side PDF
     library: the browser's Print to PDF produces a real PDF, it needs no new
     dependency, and it works the same on every deployment. A library can be
     added later if invoices need to be emailed as attachments.

     Carries the elements an invoice is generally expected to have: the word
     INVOICE, a unique number, issue and due dates, both parties' particulars,
     itemised lines with quantity and unit price, the total, the currency, and
     payment terms. Tax fields print only when configured -- local requirements
     have not been reviewed by an accountant. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_','-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<title>{{ __('Invoice') }} #{{ $invoice->number }} — {{ $seller['name'] }}</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;background:#eef1f5;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#1a2430;font-size:14px;line-height:1.55}
  .sheet{max-width:820px;margin:24px auto;background:#fff;padding:48px 52px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
  .actions{max-width:820px;margin:0 auto 0;padding:0 4px;display:flex;gap:10px;justify-content:space-between;align-items:center}
  .actions a,.actions button{font-size:14px;text-decoration:none;color:#17A2B8;background:none;border:none;cursor:pointer;padding:8px 0}
  .btn-print{background:#17A2B8;color:#fff;border-radius:6px;padding:8px 18px}
  header{display:flex;justify-content:space-between;align-items:flex-start;gap:30px;padding-bottom:26px;border-bottom:2px solid #1F3864}
  .brand{font-size:22px;font-weight:700;color:#1F3864;letter-spacing:-.02em}
  .brand small{display:block;font-size:12px;font-weight:400;color:#667;letter-spacing:0;margin-top:2px}
  .doc{text-align:right}
  .doc h1{margin:0;font-size:26px;letter-spacing:.12em;color:#1F3864;text-transform:uppercase}
  .doc .num{font-size:15px;color:#445;margin-top:4px}
  .parties{display:flex;gap:40px;margin:28px 0}
  .parties > div{flex:1}
  .label{font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:#8a97a6;margin-bottom:6px}
  .party strong{display:block;font-size:15px;color:#1a2430;margin-bottom:2px}
  .party span{display:block;color:#556;font-size:13.5px}
  .meta{display:flex;gap:0;border:1px solid #e2e7ee;margin-bottom:26px}
  .meta div{flex:1;padding:12px 16px;border-right:1px solid #e2e7ee}
  .meta div:last-child{border-right:0}
  .meta .v{font-size:14.5px;color:#1a2430;font-weight:600}
  table{width:100%;border-collapse:collapse;margin-bottom:22px}
  th{text-align:left;font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:#8a97a6;padding:0 10px 9px 0;border-bottom:1px solid #d7dee7}
  th.r,td.r{text-align:right;padding-right:0}
  td{padding:14px 10px 14px 0;border-bottom:1px solid #eef1f5;vertical-align:top}
  td .desc{color:#1a2430}
  td .sub{color:#8a97a6;font-size:12.5px;margin-top:3px}
  .totals{margin-left:auto;width:290px}
  .totals div{display:flex;justify-content:space-between;padding:7px 0}
  .totals .grand{border-top:2px solid #1F3864;margin-top:6px;padding-top:12px;font-size:19px;font-weight:700;color:#1F3864}
  .status{display:inline-block;padding:5px 14px;border-radius:999px;font-size:12px;font-weight:600;letter-spacing:.04em}
  .paid{background:#E3F3EC;color:#0B6E4F}
  .due{background:#FFF6DC;color:#7A5C00}
  .void{background:#F0F2F5;color:#5A6B82;text-decoration:line-through}
  footer{margin-top:34px;padding-top:20px;border-top:1px solid #e2e7ee;color:#8a97a6;font-size:12.5px;line-height:1.6}
  @media print{
    body{background:#fff}
    .actions{display:none}
    .sheet{box-shadow:none;margin:0;max-width:none;padding:0}
    @page{margin:18mm}
  }
</style>
</head>
<body>

<div class="actions">
  <a href="/portal/billing">&larr; {{ __('Back to Billing') }}</a>
  <button class="btn-print" onclick="window.print()">{{ __('Print or save as PDF') }}</button>
</div>

<div class="sheet">
  <header>
    <div>
      <div class="brand">
        {{ $seller['name'] }}
        @if ($seller['legal_name'] && $seller['legal_name'] !== $seller['name'])
          <small>{{ $seller['legal_name'] }}</small>
        @endif
      </div>
      <div style="color:#556;font-size:13px;margin-top:10px;">
        @if ($seller['address']) {{ $seller['address'] }}<br> @endif
        @if ($seller['city']) {{ $seller['city'] }}@if ($seller['country']), {{ $seller['country'] }}@endif<br> @endif
        {{ $seller['email'] }}<br>
        {{ $seller['website'] }}
        @if ($seller['tax_number']) <br>{{ __('Tax ID') }}: {{ $seller['tax_number'] }} @endif
        @if ($seller['reg_number']) <br>{{ __('Reg. no.') }}: {{ $seller['reg_number'] }} @endif
      </div>
    </div>
    <div class="doc">
      <h1>{{ __('Invoice') }}</h1>
      <div class="num">#{{ $invoice->number }}</div>
      <div style="margin-top:10px;">
        @php
          $st = $invoice->voided_at ? 'void' : $invoice->status;
          $cls = $st === 'paid' ? 'paid' : ($st === 'void' ? 'void' : 'due');
          $lbl = match ($st) { 'paid' => __('Paid'), 'void' => __('Void'), 'overdue' => __('Overdue'), default => __('Due') };
        @endphp
        <span class="status {{ $cls }}">{{ $lbl }}</span>
      </div>
    </div>
  </header>

  <div class="parties">
    <div>
      <p class="label">{{ __('Billed to') }}</p>
      <div class="party">
        <strong>{{ $profile->legal_name ?? $tenant->organization_name }}</strong>
        @if (!empty($profile->address_line1)) <span>{{ $profile->address_line1 }}</span> @endif
        @if (!empty($profile->address_line2)) <span>{{ $profile->address_line2 }}</span> @endif
        <span>
          {{ $profile->city ?? '' }}@if (!empty($profile->city) && !empty($profile->country)), @endif
          {{ !empty($profile->country) ? \App\Support\Countries::name($profile->country) : '' }}
        </span>
        @if (!empty($profile->contact_email)) <span>{{ $profile->contact_email }}</span> @endif
        @if (!empty($profile->registration_number)) <span>{{ __('Reg. no.') }}: {{ $profile->registration_number }}</span> @endif
      </div>
    </div>
  </div>

  <div class="meta">
    <div>
      <p class="label">{{ __('Issued') }}</p>
      <p class="v" style="margin:0;">{{ $invoice->issued_at ? \Carbon\Carbon::parse($invoice->issued_at)->toFormattedDateString() : '—' }}</p>
    </div>
    <div>
      <p class="label">{{ __('Due') }}</p>
      <p class="v" style="margin:0;">{{ $invoice->due_at ? \Carbon\Carbon::parse($invoice->due_at)->toFormattedDateString() : '—' }}</p>
    </div>
    <div>
      <p class="label">{{ __('Service period') }}</p>
      <p class="v" style="margin:0;">
        {{ $invoice->period_start ? \Carbon\Carbon::parse($invoice->period_start)->format('d M Y') : '' }}
        @if ($invoice->period_end) – {{ \Carbon\Carbon::parse($invoice->period_end)->format('d M Y') }} @endif
      </p>
    </div>
    <div>
      <p class="label">{{ __('Currency') }}</p>
      <p class="v" style="margin:0;">{{ $currency }}</p>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:52%">{{ __('Description') }}</th>
        <th class="r" style="width:14%">{{ __('Qty') }}</th>
        <th class="r" style="width:17%">{{ __('Unit price') }}</th>
        <th class="r" style="width:17%">{{ __('Amount') }}</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($lines as $l)
        <tr>
          <td>
            <div class="desc">{{ $l['description'] ?? __('Subscription') }}</div>
            @if (!empty($l['prorated']))
              <div class="sub">{{ __('Prorated for :n day(s) remaining in the current period', ['n' => $l['days_charged'] ?? 0]) }}</div>
            @elseif (!empty($l['months_charged']) && $l['months_charged'] > 1)
              <div class="sub">{{ __(':n months charged — two months free on annual billing', ['n' => $l['months_charged']]) }}</div>
            @endif
          </td>
          <td class="r">{{ $l['quantity'] ?? 1 }}</td>
          <td class="r">
            @if (!empty($l['unit_cents']))
              ${{ number_format($l['unit_cents'] / 100, 2) }}
              <div class="sub">{{ __('per device / month') }}</div>
            @else
              —
            @endif
          </td>
          <td class="r">${{ number_format(($l['total_cents'] ?? 0) / 100, 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="totals">
    <div><span style="color:#556;">{{ __('Subtotal') }}</span><span>${{ number_format($invoice->amount_usd_cents / 100, 2) }}</span></div>
    <div><span style="color:#556;">{{ __('Tax') }}</span><span>{{ __('Not applied') }}</span></div>
    <div class="grand"><span>{{ __('Total') }}</span><span>${{ number_format($invoice->amount_usd_cents / 100, 2) }} {{ $currency }}</span></div>
  </div>

  <footer>
    <strong style="color:#556;">{{ __('Payment terms') }}:</strong> {{ $terms }}<br>
    @if ($invoice->paid_at)
      {{ __('Paid on :date. Thank you.', ['date' => \Carbon\Carbon::parse($invoice->paid_at)->toFormattedDateString()]) }}<br>
    @endif
    @if ($invoice->voided_at)
      {{ __('This invoice was voided on :date. :reason', [
          'date' => \Carbon\Carbon::parse($invoice->voided_at)->toFormattedDateString(),
          'reason' => $invoice->void_reason ?? '',
      ]) }}<br>
    @endif
    {{ __('All amounts are in :currency.', ['currency' => $currency]) }}
    {{ __('Questions about this invoice? Contact :email.', ['email' => $seller['email']]) }}
  </footer>
</div>

</body>
</html>
