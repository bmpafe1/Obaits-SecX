<div style="font-family:Arial,sans-serif;color:#0B2545;">
    <h2 style="color:#0B2545;">{{ $subjectLine }}</h2>
    <p style="color:#5A6B82;line-height:1.6;">{{ $bodyText }}</p>
    @if ($link)
        <p>
            <a href="{{ rtrim(config('app.url'), '/') . $link }}"
               style="display:inline-block;background:#17A2B8;color:#fff;padding:.6rem 1.2rem;border-radius:8px;text-decoration:none;">
                View in Obaits
            </a>
        </p>
    @endif
    <hr style="border:none;border-top:1px solid #eee;margin:1.5rem 0;">
    <p style="color:#9aa;font-size:.8rem;">Obaits — your security platform.</p>
</div>