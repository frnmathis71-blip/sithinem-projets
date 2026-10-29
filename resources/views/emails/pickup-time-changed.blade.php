<x-mail::message>
# Votre heure de retrait a changé

Bonjour {{ $order->customer_name }},

Le restaurant a modifié l’heure de retrait de votre commande **{{ $order->number }}**.

- Ancienne heure : ~~{{ $previous->date->format('d/m/Y') }} à {{ substr($previous->starts_at, 0, 5) }}~~
- **Nouvelle heure : {{ $order->slot->date->format('d/m/Y') }} à {{ substr($order->slot->starts_at, 0, 5) }}**

<x-mail::button :url="route('orders.show', $order)">
Suivre ma commande
</x-mail::button>

Si cet horaire ne vous convient pas, contactez-nous.

À bientôt,<br>
Sithinem
</x-mail::message>
