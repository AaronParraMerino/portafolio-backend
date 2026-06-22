@component('emails.layout', ['title' => 'Rol publicante actualizado'])
    <h1 style="margin:0 0 16px; color:#0f4164; font-size:24px; line-height:1.25; font-weight:700;">Tu rol publicante fue actualizado</h1>
    <p style="margin:0 0 14px; color:#294b61; font-size:15px; line-height:1.7;">Hola {{ $nombre }},</p>
    <p style="margin:0 0 18px; color:#294b61; font-size:15px; line-height:1.7;">El equipo de administracion actualizo tus permisos de publicante en CreaFolio.</p>
    <div style="margin:0 0 18px; padding:16px 18px; background:#e5f4fb; border-left:4px solid #0077b7; border-radius:8px; color:#294b61; font-size:15px; line-height:1.7;"><strong style="color:#0f4164;">Motivo:</strong> {{ $mensaje }}</div>
    <p style="margin:0; color:#294b61; font-size:15px; line-height:1.7;">Si tienes dudas sobre este cambio, contacta al equipo de administracion.</p>
@endcomponent
