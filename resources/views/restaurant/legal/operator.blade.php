@if(!config('site.operator') || !config('site.address') || !config('site.privacy_email') || !config('site.host') || !config('site.retention'))
<p class="notice">Document en cours de finalisation : les informations de l’exploitant, de l’hébergement et les durées de conservation doivent être complétées avant l’ouverture du service.</p>
@endif
<p>Exploitant : {{ config('site.operator') ?: 'À renseigner' }}<br>Adresse : {{ config('site.address') ?: 'À renseigner' }}<br>Contact : {{ config('site.privacy_email') ?: 'À renseigner' }}<br>Hébergement : {{ config('site.host') ?: 'À renseigner' }}</p>
