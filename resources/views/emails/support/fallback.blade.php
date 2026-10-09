@component('mail::message')
# Nuevo mensaje en: {{ $conversation->subject }}

Estimado/a,

Ha recibido un nuevo mensaje en la conversación de soporte **{{ $conversation->subject }}**.

## Mensaje
{{ $message->body_text }}

---

Puede responder directamente a este correo o acceder a la conversación en Consultora DH:

@component('mail::button', ['url' => $appUrl . '/portal/soporte/' . $conversation->id])
Ver conversación
@endcomponent

Si no desea recibir notificaciones por correo, puede desactivarlas en la configuración de su perfil.

Atentamente,<br>
{{ config('app.name') }}
@endcomponent