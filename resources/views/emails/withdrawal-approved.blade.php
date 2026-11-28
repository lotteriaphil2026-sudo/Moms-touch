<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Withdrawal Request Approved</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f5;font-family:Arial,Helvetica,sans-serif;color:#252525;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f3f4f5">
    <tr><td align="center" style="padding:28px 12px;">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" bgcolor="#ffffff" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e4e5e7;border-radius:12px;">
            <tr><td height="5" bgcolor="#efb62e" style="height:5px;font-size:0;line-height:0;">&nbsp;</td></tr>
            <tr><td align="center" style="padding:29px 24px 24px;border-bottom:1px solid #ececec;">
                <div style="font-size:27px;line-height:34px;font-weight:800;color:#222;">MOM&#8217;S TOUCH<span style="color:#d99b13;">.</span></div>
                <div style="font-size:10px;line-height:18px;letter-spacing:2.5px;color:#777;">MEMBER SERVICES</div>
            </td></tr>
            <tr><td align="center" style="padding:30px 22px 22px;">
                <div style="width:54px;height:54px;line-height:54px;margin:auto;border-radius:50%;background:#edf6ef;color:#28734b;font-size:29px;font-weight:bold;">&#10003;</div>
                <h1 style="font-size:25px;line-height:32px;margin:14px 0 5px;color:#222;">Withdrawal Approved</h1>
                <p style="margin:0;font-size:14px;line-height:22px;color:#6a6a6a;">Your withdrawal has been approved and the funds have been sent.</p>
            </td></tr>
            <tr><td style="padding:0 24px 24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fff9e9" style="background:#fff9e9;border:1px solid #f3e4bd;border-radius:8px;">
                    <tr><td align="center" style="padding:21px 12px;">
                        <div style="font-size:10px;line-height:18px;letter-spacing:1.8px;color:#78612d;font-weight:bold;">TOTAL AMOUNT SENT</div>
                        <div style="font-size:36px;line-height:48px;font-weight:bold;color:#252525;">&#8369;{{ number_format($netCents / 100, 2) }}</div>
                    </td></tr>
                </table>
            </td></tr>
            <tr><td style="padding:0 26px 22px;font-size:14px;line-height:23px;">
                <p style="margin:0 0 10px;">Dear <strong>{{ $memberName }},</strong></p>
                <p style="margin:0;color:#505050;">We are pleased to confirm that your withdrawal request has been approved. The net amount of &#8369;{{ number_format($netCents / 100, 2) }} has been sent to your registered {{ $accountProvider }} {{ strtolower($accountType) }} after deducting the 5% withdrawal fee.</p>
            </td></tr>
            <tr><td style="padding:0 24px 24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e5e5e5;border-radius:8px;">
                    <tr><td colspan="2" bgcolor="#f7f7f7" style="padding:14px;font-size:13px;font-weight:bold;border-radius:8px 8px 0 0;">TRANSACTION DETAILS</td></tr>
                    <tr><td style="padding:13px 12px;font-size:12px;color:#6b6b6b;border-bottom:1px solid #eee;">Member name</td><td align="right" style="padding:13px 12px;font-size:13px;font-weight:bold;border-bottom:1px solid #eee;">{{ $memberName }}</td></tr>
                    <tr><td style="padding:13px 12px;font-size:12px;color:#6b6b6b;border-bottom:1px solid #eee;">Email address</td><td align="right" style="padding:13px 12px;font-size:12px;word-break:break-all;border-bottom:1px solid #eee;">{{ $memberEmail }}</td></tr>
                    <tr><td style="padding:13px 12px;font-size:12px;color:#6b6b6b;border-bottom:1px solid #eee;">Date submitted</td><td align="right" style="padding:13px 12px;font-size:13px;border-bottom:1px solid #eee;">{{ $submittedAt->format('M j, Y') }}<br>{{ $submittedAt->format('g:i A') }}</td></tr>
                    <tr><td style="padding:13px 12px;font-size:12px;color:#6b6b6b;border-bottom:1px solid #eee;">Withdrawal method</td><td align="right" style="padding:13px 12px;font-size:13px;border-bottom:1px solid #eee;">{{ $accountProvider }} {{ $accountType }}</td></tr>
                    <tr><td style="padding:13px 12px;font-size:12px;color:#6b6b6b;border-bottom:1px solid #eee;">Account name</td><td align="right" style="padding:13px 12px;font-size:13px;border-bottom:1px solid #eee;">{{ $accountName }}</td></tr>
                    <tr><td style="padding:13px 12px;font-size:12px;color:#6b6b6b;border-bottom:1px solid #eee;">Account number</td><td align="right" style="padding:13px 12px;font-size:13px;border-bottom:1px solid #eee;">{{ $accountNumber }}</td></tr>
                    <tr><td style="padding:13px 12px;font-size:12px;color:#6b6b6b;border-bottom:1px solid #eee;">Withdrawal amount</td><td align="right" style="padding:13px 12px;font-size:13px;border-bottom:1px solid #eee;">&#8369;{{ number_format($grossCents / 100, 2) }}</td></tr>
                    <tr><td style="padding:13px 12px;font-size:12px;color:#6b6b6b;border-bottom:1px solid #eee;">Withdrawal fee (5%)</td><td align="right" style="padding:13px 12px;font-size:13px;border-bottom:1px solid #eee;">&minus;&#8369;{{ number_format($feeCents / 100, 2) }}</td></tr>
                    <tr><td bgcolor="#fff9e9" style="padding:15px 12px;font-size:13px;font-weight:bold;">Total amount sent</td><td align="right" bgcolor="#fff9e9" style="padding:15px 12px;font-size:17px;font-weight:bold;">&#8369;{{ number_format($netCents / 100, 2) }}</td></tr>
                    <tr><td style="padding:13px 12px;font-size:12px;color:#6b6b6b;">Status</td><td align="right" style="padding:13px 12px;"><span style="background:#edf6ef;color:#28734b;padding:6px 10px;border-radius:20px;font-size:12px;font-weight:bold;">Approved</span></td></tr>
                    <tr><td colspan="2" style="border-top:1px solid #eee;padding:13px 12px;">
                        <div style="font-size:12px;color:#6b6b6b;margin-bottom:6px;">Transaction reference</div>
                        <div style="font-size:12px;line-height:18px;word-break:break-all;color:#333;">{{ $reference }}</div>
                    </td></tr>
                </table>
            </td></tr>
            <tr><td style="padding:0 26px 28px;font-size:14px;line-height:23px;color:#505050;">
                Thank you.<br><br>Kind regards,<br><strong style="color:#222;">Member Services Team</strong><br>Mom&#8217;s Touch
            </td></tr>
            <tr><td align="center" bgcolor="#f8f8f8" style="padding:18px 24px;border-top:1px solid #e9e9e9;border-radius:0 0 12px 12px;font-size:11px;line-height:18px;color:#777;">
                Withdrawal notification &bull; Member Services<br>For your security, never share your password or one-time PIN.
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
