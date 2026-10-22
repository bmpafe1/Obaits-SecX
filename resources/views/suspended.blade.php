<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Suspended · Obaits</title>
</head>
<body style="font-family:system-ui,-apple-system,sans-serif;background:#f4f6f9;margin:0;">
    <div style="max-width:480px;margin:6rem auto;padding:0 1rem;text-align:center;">
        <div style="background:#fff;border-radius:12px;padding:2.5rem 2rem;box-shadow:0 2px 12px rgba(0,0,0,.08);">
            <div style="font-size:2.5rem;">&#9888;&#65039;</div>
            <h1 style="color:#0B2545;margin:.5rem 0;">{{ __('Account suspended') }}</h1>
            <p style="color:#5A6B82;line-height:1.6;">
                {{ __('Access to this account is currently suspended. If you believe this is a mistake, please contact support.') }}
            </p>
            <a href="mailto:{{ config('platform.support_email') }}"
               style="display:inline-block;margin-top:1rem;background:#0B2545;color:#fff;padding:.6rem 1.4rem;border-radius:8px;text-decoration:none;">
                {{ __('Contact support') }}
            </a>
        </div>
    </div>
</body>
</html>