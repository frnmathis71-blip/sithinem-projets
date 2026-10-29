{{-- Welcome illustration, then current offers and chef dishes; resources/js/app.js turns the slides every 5 seconds. --}}
@php($count = count($slides) + 1)
<div class="hero-carousel" @if($count > 1) data-hero-carousel role="region" aria-roledescription="carrousel" aria-label="À la une" @endif>
    <div class="hero-slides">
        <div class="hero-slide is-active" @if($count > 1) role="group" aria-roledescription="diapositive" aria-label="1 sur {{ $count }}" @endif>
            <div class="hero-art" aria-hidden="true"><div class="art-ring"></div><div class="plate"><div class="food-leaf leaf-one"></div><div class="food-leaf leaf-two"></div><div class="nem nem-one"></div><div class="nem nem-two"></div><div class="nem nem-three"></div><div class="sauce"><span></span></div></div></div>
        </div>
        @foreach($slides as $slide)
            <div class="hero-slide" role="group" aria-roledescription="diapositive" aria-label="{{ $loop->iteration + 1 }} sur {{ $count }} : {{ $slide['name'] }}" inert>
                <a class="hero-slide-link" href="{{ $slide['url'] }}">
                    @if($slide['image'])<img src="{{ $slide['image'] }}" alt="" loading="lazy">@else<img class="is-placeholder" src="{{ asset('images/product-placeholder.svg') }}" alt="" loading="lazy">@endif
                    <span class="hero-slide-caption"><span class="eyebrow">{{ $slide['label'] }}</span><strong>{{ $slide['name'] }}</strong><span class="hero-slide-price">{{ $slide['price'] }}</span><span class="hero-slide-more">Découvrir <span aria-hidden="true">→</span></span></span>
                </a>
            </div>
        @endforeach
    </div>
    @if($count > 1)
        <div class="hero-carousel-controls">
            @for($i = 0; $i < $count; $i++)
                <button type="button" class="hero-carousel-dot" data-carousel-dot="{{ $i }}" aria-label="Afficher la diapositive {{ $i + 1 }}" @if($i === 0) aria-current="true" @endif></button>
            @endfor
        </div>
    @endif
</div>
