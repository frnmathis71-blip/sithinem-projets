<x-restaurant-layout title="Formules de menu" :admin="true">
<div class="section-heading"><div><p class="eyebrow">VOS FORMULES, LEURS ENVIES</p><h1>Formules de menu</h1><p>Définissez les catégories et le prix. Vos clients choisissent leurs produits dans la formule.</p></div><a class="button" href="{{ route('admin.menu-rules.create') }}">Créer une formule +</a></div>
<div class="order-list">@forelse($rules as $rule)
<a class="panel order-summary" href="{{ route('admin.menu-rules.edit', $rule) }}"><div><h2>{{ $rule->label() }}</h2><p>{{ $rule->categoryLabel() }}</p><span class="badge">{{ $rule->available ? 'Active' : 'Inactive' }}</span> <span class="badge">{{ $rule->visible ? 'Visible dans Menus' : 'Masquée dans Menus' }}</span><p class="muted">Ordre : {{ $rule->position }}</p></div><strong>{{ number_format($rule->price / 100, 2, ',', ' ') }} € · Modifier ↗</strong></a>
@empty<div class="empty-state"><h2>Votre première formule</h2><p>Choisissez les catégories, donnez-lui un nom et fixez son prix.</p><a class="button" href="{{ route('admin.menu-rules.create') }}">Créer une formule</a></div>@endforelse</div>
<p><a href="{{ route('admin.card') }}">← Retour à la carte</a></p>
</x-restaurant-layout>
