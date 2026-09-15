<div class="variant-fields" data-variant-row>
    <input type="hidden" name="variants[{{ $index }}][id]" value="{{ $variant['id'] ?? '' }}">
    <label>Déclinaison<input name="variants[{{ $index }}][label]" value="{{ $variant['label'] }}" maxlength="100" placeholder="Grande · poulet" data-variant-required></label>
    <div class="two-fields"><label>Prix en euros<input name="variants[{{ $index }}][price]" value="{{ $variant['price'] }}" inputmode="decimal" placeholder="12,50" data-variant-required></label><label>Disponibilité<select name="variants[{{ $index }}][available]"><option value="1" @selected($variant['available'])>Disponible</option><option value="0" @selected(!$variant['available'])>Indisponible</option></select></label></div>
    <label>Photo de la déclinaison · 20 Mo maximum<input type="file" name="variants[{{ $index }}][image]" accept="image/jpeg,image/png,image/webp"></label>
    @php($photo = isset($variant['id']) && $variant['id'] ? $item->variants->firstWhere('id', $variant['id'])?->image : null)
    @if($photo)<img class="edit-image" src="{{ Storage::disk('public')->url($photo) }}" alt="Photo de la déclinaison"><label class="checkbox-label"><input type="checkbox" name="variants[{{ $index }}][remove_image]" value="1"> Retirer la photo</label>@endif
    <button type="button" class="text-button" data-remove-variant>Retirer cette déclinaison</button>
</div>
