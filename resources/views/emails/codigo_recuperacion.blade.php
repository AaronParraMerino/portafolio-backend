<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperacion de cuenta</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <h2>Recuperacion de cuenta</h2>
    <p>Recibimos una solicitud para recuperar la cuenta asociada a: <strong>{{ $correo }}</strong>.</p>
    <p>Tu codigo de verificacion es:</p>
    <p style="font-size: 24px; font-weight: bold; letter-spacing: 4px;">{{ $codigo }}</p>
    <p>Este codigo expira en {{ $minutosExpiracion }} minutos.</p>
    <p>Si no solicitaste este cambio, ignora este correo.</p>
</body>
</html>