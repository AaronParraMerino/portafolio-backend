<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmar vinculación de cuenta</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; background: #f9fafb; padding: 32px;">
    <div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <h2 style="margin-top: 0; color: #111827;">Vinculación de cuenta — {{ ucfirst($provider) }}</h2>
        <p>Recibimos una solicitud para vincular tu cuenta de <strong>{{ ucfirst($provider) }}</strong> a la cuenta registrada con:</p>
        <p style="font-size: 16px; font-weight: bold;">{{ $correo }}</p>
        <p>Tu código de verificación es:</p>
        <p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #2563eb; text-align: center; padding: 16px 0;">{{ $codigo }}</p>
        <p style="color: #6b7280; font-size: 14px;">Este código expira en {{ $minutosExpiracion }} minutos.</p>
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">
        <p style="color: #6b7280; font-size: 13px;">Si no solicitaste esta vinculación, ignora este correo. Tu cuenta no será modificada.</p>
    </div>
</body>
</html>
