<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Your Roll4It code</title>
</head>
<body style="margin:0;padding:0;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="440" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(15,23,42,0.06);">
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <h1 style="margin:0 0 6px;font-size:18px;font-weight:600;color:#111827;">{{ $shopName }}</h1>
                            <p style="margin:0;font-size:14px;color:#4b5563;line-height:1.5;">
                                Enter this code in your cart to confirm your email and roll for your discount.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:16px 32px 8px;">
                            <div style="display:inline-block;padding:14px 24px;background:#111827;color:#ffffff;font-size:28px;letter-spacing:6px;font-weight:700;border-radius:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
                                {{ $code }}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 32px 24px;">
                            <p style="margin:0;font-size:12px;color:#6b7280;line-height:1.5;text-align:center;">
                                This code expires in {{ max(1, $ttlMinutes) }} minutes. If you didn't ask for it, you can ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
                <p style="margin:16px 0 0;font-size:11px;color:#9ca3af;">Sent on behalf of {{ $shopName }} by Roll4It. Reply to this email to reach {{ $shopName }} directly.</p>
            </td>
        </tr>
    </table>
</body>
</html>
