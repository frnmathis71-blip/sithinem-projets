<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogService;

test('administrator can delete an empty category from the card', function () {
    $category = Category::create(['name' => 'Catégorie temporaire', 'code' => 'starter']);
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('admin.card'))->assertOk()->assertSee('Confirmer la suppression de « Catégorie temporaire »');
    $this->delete(route('admin.categories.delete', $category))->assertRedirect(route('admin.card').'#categories')->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

test('deleting a category preserves and disables its products and variants', function () {
    $category = Category::create(['name' => 'Plats', 'code' => 'main']);
    $main = Product::create(['name' => 'Plat', 'price' => 1500, 'category_id' => $category->id]);
    $parent = Product::create(['name' => 'Plat décliné', 'price' => 1500, 'category_id' => $category->id, 'has_variants' => true]);
    $variant = Product::create(['name' => 'Plat décliné', 'variant_label' => 'Grand', 'parent_id' => $parent->id, 'price' => 1900]);
    $archived = Product::create(['name' => 'Ancien plat', 'price' => 1200, 'category_id' => $category->id]);
    $archived->delete();
    $other = Product::create(['name' => 'Autre produit', 'price' => 500]);
    $this->actingAs(User::factory()->create(['role' => 'admin']))->delete(route('admin.categories.delete', $category))->assertSessionHasNoErrors();
    foreach ([$main, $parent, $variant, $archived] as $product) {
        $fresh = Product::withTrashed()->findOrFail($product->id);
        expect($fresh->category_id)->toBeNull()->and($fresh->available)->toBeFalse()->and(app(CatalogService::class)->available($fresh))->toBeFalse();
    }
    expect($main->fresh()->trashed())->toBeFalse()->and($other->fresh()->available)->toBeTrue();
});

test('customers and guests cannot delete categories', function () {
    $category = Category::create(['name' => 'À conserver']);
    $this->delete(route('admin.categories.delete', $category))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->delete(route('admin.categories.delete', $category))->assertForbidden();
    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});
