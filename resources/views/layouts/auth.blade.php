<x-restaurant-layout :title="$title ?? 'Connexion'">
    <section class="panel auth-panel">
        {{ $slot }}
    </section>
    @fluxScripts
</x-restaurant-layout>
