<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Certificate of Investment</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#333;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f5;">
    <tr><td align="center" style="padding:30px 15px;">
        <table role="presentation" width="650" cellpadding="0" cellspacing="0" border="0" style="max-width:650px;width:100%;background:#fff;border:1px solid #e5e5e5;">
            <tr><td align="center" style="padding:35px 25px;font-size:26px;font-weight:800;color:#30251d;">MOM&#8217;S TOUCH<span style="color:#ffad19;">.</span></td></tr>
            <tr><td style="height:4px;background:#ffad19;font-size:0;line-height:0;">&nbsp;</td></tr>
            <tr><td align="center" style="padding:35px 25px 5px;">
                <h1 style="margin:0;font-size:25px;line-height:34px;color:#30251d;">Certificate of Investment</h1>
                <p style="margin:10px 0 0;font-size:13px;color:#888;">Certificate Reference No. {{ $certificateNumber }}</p>
            </td></tr>
            <tr><td style="padding:25px 30px;font-size:15px;line-height:26px;color:#333;">
                <p style="margin:0 0 20px;">Dear <strong>{{ $holderName }},</strong></p>
                <p>Greetings!</p>
                <p>Please find enclosed a copy of your Certificate of Investment corresponding to your selected Mom&#8217;s Touch plan, for your review and personal records.</p>
                <table role="presentation" width="100%" cellpadding="12" cellspacing="0" border="0" style="margin:25px 0;background:#faf8f3;border:1px solid #e4dccb;font-size:14px;">
                    <tr><td colspan="2" style="color:#92713a;font-weight:bold;letter-spacing:1.5px;font-size:12px;border-bottom:1px solid #e4dccb;">CERTIFICATE DETAILS</td></tr>
                    <tr><td style="color:#666;">Certificate Holder</td><td align="right"><strong>{{ $holderName }}</strong></td></tr>
                    <tr><td style="color:#666;">Certificate Number</td><td align="right"><strong>{{ $certificateNumber }}</strong></td></tr>
                    <tr><td style="color:#666;">Selected Plan</td><td align="right"><strong>{{ $planName }}</strong></td></tr>
                    <tr><td style="color:#666;">Date Indicated</td><td align="right"><strong>{{ $indicatedDate }}</strong></td></tr>
                </table>
                <p>Kindly retain a copy of this certificate with your corresponding signed agreement for future reference.</p>
            </td></tr>
            <tr><td align="center" style="padding:0 25px 25px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #ded5c5;background:#faf8f3;">
                    <tr><td align="center" style="padding:12px;font-size:11px;letter-spacing:2px;color:#92713a;font-weight:bold;">CERTIFICATE COPY</td></tr>
                    @if($isImage)
                    <tr><td align="center" style="padding:10px;"><img src="{{ $certificateUrl }}" alt="Certificate {{ $certificateNumber }}" width="542" style="max-width:100%;height:auto;border:0;"></td></tr>
                    @endif
                    <tr><td align="center" style="padding:10px 15px 18px;font-size:12px;color:#777;">Certificate No. {{ $certificateNumber }}<br><a href="{{ $certificateUrl }}" style="color:#92713a;">View or download certificate</a></td></tr>
                </table>
            </td></tr>
            <tr><td style="padding:0 30px 35px;font-size:15px;line-height:26px;color:#333;">
                <p>Should you have any questions or require further assistance regarding this document, please do not hesitate to contact us.</p>
                <p>Thank you.</p>
                <p style="margin-top:30px;">Best regards,<br><strong style="color:#30251d;">&nbsp;&nbsp;&nbsp;{{ $senderName }}</strong><br><span style="font-size:13px;color:#777;">Investor Relations</span></p>
            </td></tr>
            <tr><td style="background:#30251d;padding:25px 20px;text-align:center;">
                <p style="margin:0 0 10px;font-size:18px;font-weight:bold;color:#ffad19;">MOM&#8217;S TOUCH</p>
                <p style="margin:0;font-size:11px;line-height:19px;color:#ded8d2;">This correspondence is intended exclusively for the designated recipient. Please keep your documents secure and confidential.</p>
            </td></tr>
        </table>
        <p style="max-width:600px;margin:20px auto 0;font-size:11px;line-height:18px;color:#999;text-align:center;">If you have received this correspondence in error, please notify the sender and disregard this message.</p>
    </td></tr>
</table>
</body>
</html>
