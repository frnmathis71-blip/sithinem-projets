<x-restaurant-layout title="VÃ©rifier mon tÃ©lÃ©phone">
    <div class="section-heading"><div><p class="eyebrow">VOTRE NUMÃ‰RO DE CONTACT</p><h1>VÃ©rifier mon tÃ©lÃ©phone</h1></div></div>
    <section class="panel">
        @if(app(\App\Services\PhoneVerificationService::class)->simulated())
            <p class="notice">Simulation locale : aucun SMS n’est envoyé. Retrouvez le code dans storage/logs/laravel.log (valable 10 minutes).
                @if($simulationCode = app(\App\Services\PhoneVerificationService::class)->previewCode(auth()->user()->pending_phone))
                    <strong>Code de vérification : <span class="verification-preview">{{ $simulationCode }}</span></strong>
                @endif
            </p>
        @endif
        <p>Confirmez votre numÃ©ro par SMS avant votre premiÃ¨re commande. Votre panier est conservÃ©.</p>
        @if(auth()->user()->phone_verified_at)<p>NumÃ©ro vÃ©rifiÃ© : {{ auth()->user()->phone }}</p>@endif
        <form method="post" action="{{ route('phone.send') }}">
            @csrf <x-spam-trap />
            <label for="verification-phone">TÃ©lÃ©phone</label>
            <input id="verification-phone" type="tel" name="phone" autocomplete="tel" maxlength="30" required value="{{ old('phone', auth()->user()->pending_phone ?? auth()->user()->phone) }}" placeholder="06 12 34 56 78">
            <p class="muted">Pour un numÃ©ro Ã©tranger, indiquez le prÃ©fixe international, par exemple +32.</p>
            <button class="button">{{ auth()->user()->pending_phone ? 'Envoyer ou renvoyer un code SMS' : 'Recevoir un code SMS' }}</button>
            <p class="muted">Patientez au moins une minute entre deux envois.</p>
        </form>
        @if(auth()->user()->pending_phone)
            <form method="post" action="{{ route('phone.check') }}">
                @csrf <x-spam-trap />
                <label for="verification-code">Code reÃ§u sur {{ auth()->user()->pending_phone }}</label>
                <input id="verification-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4,10}" minlength="4" maxlength="10" required>
                <button class="button">VÃ©rifier mon numÃ©ro</button>
            </form>
        @endif
        <a href="{{ route('cart') }}">Revenir au panier</a>
    </section>
</x-restaurant-layout>
