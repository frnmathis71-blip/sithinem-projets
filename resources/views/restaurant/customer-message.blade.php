@if($order->customer_message !== null && $order->customer_message !== '')
<div class="notice" style="border-left: 4px solid #b5691c; margin: 16px 0;">
    <strong>Message du client · À lire avant préparation</strong>
    <p style="white-space: pre-wrap; overflow-wrap: anywhere; margin-bottom: 0;">{{ $order->customer_message }}</p>
</div>
@endif
