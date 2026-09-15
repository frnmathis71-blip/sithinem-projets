<x-restaurant-layout :title="$rule ? $rule->label() : 'Choisir mon accompagnement'">
<div class="section-heading story-heading"><div><p class="eyebrow">VOTRE MENU, À VOTRE GOÛT</p><h1>{{ $rule ? $rule->label() : $product->displayName() }}</h1>@if($rule?->description)<p>{{ $rule->description }}</p>@endif</div><a href="{{ $rule ? route('menus.index') : route('menu') }}">← {{ $rule ? 'Les menus' : 'La carte' }}</a></div>
<form method="post" action="{{ route('menus.add') }}" class="formula-story" data-formula-selection data-base-price="{{ $rule ? $rule->price : $product->price }}" data-pair="{{ $product ? '1' : '0' }}">@csrf
<script type="application/json" data-formula-options>@json($options)</script>
@if($rule)<input type="hidden" name="rule_id" value="{{ $rule->id }}">@else<input type="hidden" name="selections[main]" value="{{ $product->id }}">@endif
<div class="story-progress"><span data-story-progress aria-live="polite">Étape 1 / {{ count($steps) }}</span><span class="muted">Un choix par écran</span><progress data-story-bar max="{{ count($steps) }}" value="1" aria-label="Progression dans le menu"></progress></div>
<noscript><p class="notice">Activez JavaScript pour parcourir les écrans de sélection de votre menu.</p></noscript>
<div data-story-surface class="story-surface">
@foreach($steps as $code)
@php($choices = array_values(array_filter($options, fn($option) => $option['code'] === $code)))
@php($titles = ['starter' => 'Choisissez votre entrée', 'main' => 'Choisissez votre plat', 'chef_main' => 'Choisissez votre plat du chef', 'side' => 'Choisissez votre accompagnement', 'dessert' => 'Choisissez votre dessert', 'drink' => 'Choisissez votre boisson'])
<fieldset class="story-screen" data-formula-step="{{ $code }}" @if(!$loop->first) hidden @endif>
<legend tabindex="-1">{{ $titles[$code] }}</legend>
@if($code === 'side')<p class="muted">Les accompagnements compatibles avec votre plat.</p>@elseif($code === 'chef_main')<p class="muted">La garniture est déjà incluse dans chaque plat du chef.</p>@else<p class="muted">Touchez une carte pour faire votre choix.</p>@endif
<div class="story-product-grid">
@foreach(collect($choices)->groupBy('group_id') as $groupId => $variants)
@php($option = $variants->first())
<div data-product-group="{{ $groupId }}" class="story-group">
@foreach($variants as $variant)
<span data-product-card hidden><input type="radio" name="selections[{{ $code }}]" value="{{ $variant['id'] }}" data-formula-choice="{{ $code }}" aria-label="{{ $variant['name'] }}" @checked((string) old('selections.'.$code) === (string) $variant['id']) @disabled($code === 'main' && !$variant['side_ids'])></span>
@endforeach
<button type="button" class="story-product" data-choose-group="{{ $groupId }}" aria-pressed="false">
<span class="story-card-body"><span class="story-image-wrap"><img src="{{ $option['group_image'] }}" alt="{{ $option['group_name'] }}" width="640" height="420" draggable="false" loading="lazy">@if(!$option['has_photo'])<span class="story-photo-note">Photo à venir</span>@endif<span class="story-check" aria-hidden="true">✓</span></span><span class="story-product-name">{{ $option['group_name'] }}</span><span class="story-product-note" data-group-choice></span>@if($product)<span class="story-product-note">{{ $variants->count() > 1 ? 'À partir de ' : '' }}{{ number_format($variants->min('price') / 100, 2, ',', ' ') }} €</span>@endif@if($code === 'chef_main')<span class="story-product-note">Garniture incluse</span>@endif</span>
</button></div>
@endforeach
</div>
<p class="notice" data-story-empty @if($choices) hidden @endif>Aucun produit disponible pour ce choix. Revenez à la carte ou choisissez un autre plat.</p>
</fieldset>
@endforeach
</div>
<footer class="story-controls"><div class="story-recap"><div data-formula-summary aria-live="polite">Aucun produit sélectionné.</div><strong data-formula-total>{{ number_format(($rule ? $rule->price : $product->price) / 100, 2, ',', ' ') }} €{{ $product ? ' + accompagnement' : '' }}</strong></div><div class="story-buttons"><button type="button" class="button secondary" data-story-previous disabled>Précédent</button><span class="story-swipe-hint muted">Glissez pour changer d’écran</span><button type="button" class="button" data-story-next disabled @if(count($steps) === 1) hidden @endif>Suivant →</button><button class="button" data-formula-submit disabled @if(count($steps) !== 1) hidden @endif>Ajouter au panier</button></div><p class="muted" data-formula-status aria-live="polite">Sélectionnez un produit pour continuer.</p></footer>
</form>@include('restaurant.variant-dialog')</x-restaurant-layout>
