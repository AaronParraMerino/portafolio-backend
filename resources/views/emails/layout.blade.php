<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'CreaFolio' }}</title>
</head>
<body style="margin:0; padding:0; background:#eef6fb; font-family:Arial, Helvetica, sans-serif; color:#163247;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; background:#eef6fb; margin:0; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; max-width:560px; border-collapse:collapse;">
                    <tr>
                        <td style="background:#0077b7; padding:26px 32px; border-radius:14px 14px 0 0;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        <img src="https://ewillyhiihfwzveiymrq.supabase.co/storage/v1/object/public/imagenes/mail/iconoCreaFolio.png" alt="CreaFolio" width="74" style="display:block; width:74px; max-width:74px; height:auto;">
                                    </td>
                                    <td align="right" style="vertical-align:middle; color:#ffffff; font-size:22px; font-weight:700; letter-spacing:0;">
                                        CreaFolio
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff; padding:34px 32px 30px; border-left:1px solid #cfe8f6; border-right:1px solid #cfe8f6;">
                            {{ $slot }}
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f8fcff; padding:18px 32px; border:1px solid #cfe8f6; border-top:0; border-radius:0 0 14px 14px; color:#5f7f94; font-size:12px; line-height:1.6; text-align:center;">
                            Este mensaje fue enviado por CreaFolio. Si no reconoces esta actividad, puedes ignorar este correo.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
