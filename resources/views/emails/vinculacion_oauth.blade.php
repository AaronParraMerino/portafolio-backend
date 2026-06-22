@component('emails.layout', ['title' => 'Confirmar vinculacion de cuenta'])
    <h1 style="margin:0 0 16px; color:#0f4164; font-size:24px; line-height:1.25; font-weight:700;">Vinculacion de cuenta - {{ ucfirst($provider) }}</h1>
    <p style="margin:0 0 16px; color:#294b61; font-size:15px; line-height:1.7;">Recibimos una solicitud para vincular tu cuenta de <strong style="color:#0f4164;">{{ ucfirst($provider) }}</strong> a la cuenta registrada con:</p>
    <p style="margin:0 0 20px; color:#0f4164; font-size:16px; line-height:1.5; font-weight:700;">{{ $correo }}</p>
    <p style="margin:0 0 12px; color:#294b61; font-size:15px; line-height:1.7;">Tu codigo de verificacion es:</p>
    <div style="margin:0 0 20px; padding:18px 20px; background:#e5f4fb; border:1px solid #86c8e8; border-radius:10px; color:#0077b7; font-size:32px; line-height:1; font-weight:800; letter-spacing:8px; text-align:center;">{{ $codigo }}</div>
    <p style="margin:0 0 12px; color:#294b61; font-size:15px; line-height:1.7;">Este codigo expira en {{ $minutosExpiracion }} minutos.</p>
    <p style="margin:0; color:#6b8798; font-size:13px; line-height:1.7;">Si no solicitaste esta vinculacion, ignora este correo. Tu cuenta no sera modificada.</p>
@endcomponent
