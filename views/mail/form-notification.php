<?php /** @var string $htmlSite @var string $htmlSubject @var string $htmlBody @var string $htmlLang */ ?>
<!doctype html>
<html lang="<?= $htmlLang ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $htmlSubject ?></title></head>
<body style="margin:0;padding:0;background:#f4f6f7;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f4f6f7;">
    <tr><td align="center" style="padding:24px 12px;">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;border-collapse:collapse;background:#ffffff;border-top:4px solid #226654;">
            <tr><td style="padding:28px 24px 0;">
                <div style="font-size:12px;font-weight:bold;letter-spacing:1px;color:#226654;overflow-wrap:anywhere;word-break:break-word;"><?= $htmlSite ?></div>
                <h1 style="margin:16px 0 24px;font-size:24px;line-height:1.3;font-weight:600;color:#18252e;overflow-wrap:anywhere;word-break:break-word;"><?= $htmlSubject ?></h1>
            </td></tr>
            <tr><td style="padding:0 24px 12px;">
                <?= $htmlBody ?>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
