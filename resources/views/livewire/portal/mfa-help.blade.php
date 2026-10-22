<div>
    <h1 style="color:#0B2545;">{{ __('Signing in safely') }}</h1>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin:1rem 0;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;color:#0B2545;">{{ __('Why you are asked for a code') }}</h3>
        <p style="color:#5A6B82;">
            {{ __('To keep your organisation\'s security information safe, signing in now needs two things: your password, and a code from an app on your phone. Even if someone learns your password, they cannot get in without your phone.') }}
        </p>

        <h3 style="color:#0B2545;">{{ __('What to do (once, takes two minutes)') }}</h3>
        <ol style="color:#5A6B82;line-height:1.8;">
            <li>{{ __('On your phone, install a free authenticator app — Google Authenticator, Microsoft Authenticator, or Aegis.') }}</li>
            <li>{{ __('When you sign in, you will see a square barcode. Open the app, tap the + button, and scan it.') }}</li>
            <li>{{ __('The app shows a 6-digit code that changes every 30 seconds. Type the current one to finish.') }}</li>
            <li>{{ __('From now on, signing in asks for your password and the current code.') }}</li>
        </ol>
    </div>

    <div style="background:#fff8e1;border-left:4px solid #b8860b;border-radius:8px;padding:1.5rem;margin:1rem 0;">
        <h3 style="margin-top:0;color:#8a6d0b;">{{ __('Your recovery codes — do not skip this') }}</h3>
        <p style="color:#5A6B82;">
            {{ __('When you set up the code app, you will be shown a list of one-time recovery codes. Save these now — print them, or store them in a password manager. Each one lets you sign in once if you ever lose your phone.') }}
        </p>
        <p style="color:#5A6B82;margin-bottom:0;">
            <strong>{{ __('Without them, losing your phone means you cannot get in, and we cannot instantly fix it. Treat them like the spare key to your office.') }}</strong>
        </p>
    </div>
</div>