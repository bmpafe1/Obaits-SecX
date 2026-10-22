<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify your Obaits email</title>
</head>
<body style="margin:0; padding:0; background:#f4f5f7; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; padding:32px;">
                    <tr>
                        <td style="font-size:20px; font-weight:600; color:#1f2d3d; padding-bottom:16px;">
                            Obaits
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:15px; color:#33475b; line-height:1.6;">
                            <p style="margin:0 0 16px;">Hello {{ $firstName }},</p>
                            <p style="margin:0 0 24px;">Use this code to verify your email address and continue setting up your organization:</p>
                            <p style="margin:0 0 24px; text-align:center;">
                                <span style="display:inline-block; font-size:28px; letter-spacing:6px; font-weight:700; color:#1f2d3d; background:#f0f3f7; border-radius:6px; padding:14px 24px;">{{ $code }}</span>
                            </p>
                            <p style="margin:0; font-size:13px; color:#8091a5;">If you did not request this, you can ignore this email.</p>
                        </td>
                    </tr>
                </table>
                <table role="presentation" width="480" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="font-size:12px; color:#8091a5; text-align:center; padding-top:16px;">
                            Obaits security &amp; compliance platform
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>