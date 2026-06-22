@component('emails.layout', ['title' => 'Cuenta inactivada'])
    <h1 style="margin:0 0 16px; color:#0f4164; font-size:24px; line-height:1.25; font-weight:700;">Tu cuenta ha sido inactivada</h1>
    <p style="margin:0 0 14px; color:#294b61; font-size:15px; line-height:1.7;">Hola {{ $nombre }},</p>
    <p style="margin:0 0 18px; color:#294b61; font-size:15px; line-height:1.7;">Tu cuenta de CreaFolio fue inactivada por administracion.</p>
    <div style="margin:0 0 18px; padding:16px 18px; background:#e5f4fb; border-left:4px solid #0077b7; border-radius:8px; color:#294b61; font-size:15px; line-height:1.7;"><strong style="color:#0f4164;">Motivo:</strong> {{ $razon }}</div>
    <p style="margin:0; color:#294b61; font-size:15px; line-height:1.7;">Tu contenido personal dejo de estar visible. Si consideras que esto es un error o necesitas recuperar el acceso, contacta al equipo de administracion.</p>
@endcomponent
