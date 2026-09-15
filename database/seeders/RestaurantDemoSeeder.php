<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MenuRule;
use App\Models\Product;
use Illuminate\Database\Seeder;

class RestaurantDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Les données de démonstration sont réservées au développement.');
        }
        $starter = Category::query()->firstOrCreate(['name' => 'À partager'], ['position' => 1]);
        $main = Category::query()->firstOrCreate(['name' => 'Les généreux'], ['position' => 2]);
        $dessert = Category::query()->firstOrCreate(['name' => 'La touche sucrée'], ['position' => 3]);
        $drink = Category::query()->firstOrCreate(['name' => 'Les boissons'], ['position' => 4]);
        foreach (['starter' => $starter, 'main' => $main, 'dessert' => $dessert, 'drink' => $drink] as $code => $category) {
            if ($category->getAttribute('code') === null) {
                $category->update(['code' => $code]);
            }
        }
        $side = Category::query()->firstOrCreate(['name' => 'Accompagnements'], ['position' => 3, 'code' => 'side']);
        Product::query()->firstOrCreate(['name' => 'Riz parfumé'], ['description' => 'Une portion de riz parfumé.', 'price' => 300, 'category_id' => $side->id]);
        Product::query()->firstOrCreate(['name' => 'Légumes sautés'], ['description' => 'Des légumes croquants de saison.', 'price' => 400, 'category_id' => $side->id]);
        // Examples only: the back office can assemble any supported category selection.
        foreach (['main,dessert' => 'Menu Plat + Dessert', 'starter,main,dessert' => 'Menu Entrée + Plat + Dessert', 'chef_main,dessert' => 'Menu du chef gourmand', 'chef_main,drink' => 'Menu du chef + Boisson', 'starter,dessert' => 'Menu Entrée + Dessert'] as $key => $name) {
            MenuRule::query()->firstOrCreate(['category_key' => $key], ['name' => $name, 'price' => 2000, 'available' => true, 'visible' => true]);
        }
        Product::query()->firstOrCreate(['name' => 'Nems croustillants'], ['description' => 'Quatre nems dorés, salade croquante, menthe et sauce maison.', 'price' => 650, 'category_id' => $starter->id]);
        Product::query()->firstOrCreate(['name' => 'Bò bún maison'], ['description' => 'Vermicelles de riz, bœuf sauté, légumes croquants, herbes fraîches et cacahuètes.', 'price' => 1350, 'category_id' => $main->id]);
        Product::query()->firstOrCreate(['name' => 'Curry de légumes'], ['description' => 'Légumes de saison, lait de coco et riz parfumé. Doux, généreux et végétarien.', 'price' => 1150, 'category_id' => $main->id]);
        Product::query()->firstOrCreate(['name' => 'Rouleaux de printemps'], ['description' => 'Deux rouleaux frais aux crevettes, vermicelles et herbes, sauce cacahuète.', 'price' => 600, 'category_id' => $starter->id]);
        Product::query()->firstOrCreate(['name' => 'Perles de coco'], ['description' => 'Deux douceurs moelleuses à la noix de coco pour finir sur une note sucrée.', 'price' => 450, 'category_id' => $dessert->id]);
        Product::query()->firstOrCreate(['name' => 'Thé glacé maison'], ['description' => 'Thé infusé, citron et une pointe de douceur. 33 cl.', 'price' => 350, 'category_id' => $drink->id]);
        $chef = Category::query()->firstOrCreate(['name' => 'Plat du chef'], ['position' => 2, 'code' => 'chef_main']);
        if (! Product::query()->where('category_id', $chef->id)->exists()) {
            Product::query()->create(['name' => 'Assiette du chef', 'description' => 'Plat de démonstration avec sa garniture intégrée.', 'price' => 1750, 'category_id' => $chef->id]);
        }
    }
}
