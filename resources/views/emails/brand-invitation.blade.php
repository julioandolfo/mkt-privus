<x-mail::message>
# Convite para colaborar

{{ $invitation->inviter?->name ?? 'Um administrador' }} convidou você para participar da marca
**{{ $invitation->brand->name }}** como **{{ $invitation->role()->label() }}** em {{ config('app.name') }}.

<x-mail::button :url="$acceptUrl">
Aceitar convite
</x-mail::button>

Este convite expira em {{ $invitation->expires_at->format('d/m/Y') }}.
Se você ainda não tem uma conta, cadastre-se com este mesmo e-mail ({{ $invitation->email }}) e depois abra o link novamente.

Obrigado,<br>
{{ config('app.name') }}
</x-mail::message>
