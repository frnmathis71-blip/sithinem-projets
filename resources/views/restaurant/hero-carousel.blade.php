{{-- Current offers and chef dishes; resources/js/app.js turns the slides every 3 seconds. The illustration only shows when there is nothing to feature. --}}
@php($count = count($slides))
<div class="hero-carousel" @if($count > 1) data-hero-carousel role="region" aria-roledescription="carrousel" aria-label="À la une" @endif>
    <div class="hero-slides">
        @forelse($slides as $slide)
            <div class="hero-slide @if($loop->first) is-active @endif" @if($count > 1) role="group" aria-roledescription="diapositive" aria-label="{{ $loop->iteration }} sur {{ $count }} : {{ $slide['name'] }}" @unless($loop->first) inert @endunless @endif>
                <a class="hero-slide-link" href="{{ $slide['url'] }}">
                    <span class="hero-slide-media">@if($slide['image'])<img src="{{ $slide['image'] }}" alt="">@else<img class="is-placeholder" src="{{ asset('images/product-placeholder.svg') }}" alt="">@endif</span>
                    <span class="hero-slide-caption"><span class="eyebrow">{{ $slide['label'] }}{{ $slide['days'] ? ' · '.$slide['days'] : '' }}</span><strong>{{ $slide['name'] }}</strong><span class="hero-slide-price">{{ $slide['price'] }}</span><span class="hero-slide-more">Découvrir</span></span>
                </a>
            </div>
        @empty
            <div class="hero-slide is-active">
                <div class="hero-art" aria-hidden="true"><div class="art-ring"></div><div class="plate"><div class="food-leaf leaf-one"></div><div class="food-leaf leaf-two"></div><div class="nem nem-one"></div><div class="nem nem-two"></div><div class="nem nem-three"></div><div class="sauce"><span></span></div></div></div>
            </div>
        @endforelse
    </div>
    @if($count > 1)
        <div class="hero-carousel-controls">
            @for($i = 0; $i < $count; $i++)
                <button type="button" class="hero-carousel-dot" data-carousel-dot="{{ $i }}" aria-label="Afficher la diapositive {{ $i + 1 }}" @if($i === 0) aria-current="true" @endif></button>
            @endfor
        </div>
    @endif
</div>
