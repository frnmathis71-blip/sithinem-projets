<?php

namespace App\Http\Controllers\Desktop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuRule;
use App\Models\Offer;
use App\Models\Product;
use App\Services\AdminOperations;
use App\Services\CatalogService;
use App\Services\DynamicMenuService;
use App\Support\DesktopPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function index(): JsonResponse
    {
        $variant = fn (Product $variant) => ['id' => $variant->id, 'label' => $variant->variant_label, 'price' => $variant->price, 'available' => $variant->available, 'image' => DesktopPresenter::image($variant->image)];

        return response()->json([
            'categories' => Category::query()->orderBy('position')->orderBy('id')->get(['id', 'name', 'code', 'position']),
            'labels' => Category::LABELS,
            'products' => Product::query()->with('variants')->whereNull('parent_id')->orderBy('name')->get()->map(fn (Product $product) => [
                'id' => $product->id, 'name' => $product->name, 'description' => $product->description, 'price' => $product->price, 'available' => $product->available,
                'category_id' => $product->getAttribute('category_id'), 'offer_only' => $product->offer_only, 'has_variants' => $product->has_variants,
                'image' => DesktopPresenter::image($product->image), 'variants' => $product->has_variants ? $product->variants->map($variant)->values()->all() : [],
            ])->values(),
            'offers' => Offer::query()->orderBy('name')->get()->map(fn (Offer $offer) => [
                'id' => $offer->id, 'name' => $offer->name, 'price' => $offer->price, 'available' => $offer->available, 'image' => DesktopPresenter::image($offer->image),
                'ends_at' => $offer->ends_at?->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            ])->values(),
        ]);
    }

    public function item(string $kind, AdminOperations $operations, CatalogService $catalog, ?int $item = null): JsonResponse
    {
        $entry = $item ? $catalog->model($kind)::query()->findOrFail($item) : new ($catalog->model($kind));
        abort_if($entry instanceof Product && $entry->parent_id !== null, 404);
        $choices = $operations->compositionProducts($kind)->get();
        $data = ['kind' => $kind, 'id' => $entry->getKey(), 'name' => $entry->name, 'description' => $entry->description, 'price' => $entry->exists ? $entry->price : null,
            'available' => $entry->exists ? $entry->available : true, 'image' => DesktopPresenter::image($entry->image)];
        if ($entry instanceof Product) {
            $data += ['category_id' => $entry->getAttribute('category_id'), 'offer_only' => $entry->offer_only, 'has_variants' => $entry->has_variants,
                'restricted_sides' => (bool) $entry->getAttribute('restricted_sides'), 'compatible_side_ids' => $entry->getAttribute('compatible_side_ids') ?? [],
                'variants' => $entry->exists ? $entry->variants->map(fn (Product $variant) => ['id' => $variant->id, 'label' => $variant->variant_label, 'price' => $variant->price, 'available' => $variant->available, 'image' => DesktopPresenter::image($variant->image)])->values()->all() : [],
                'sides' => $choices->filter(fn (Product $product) => $product->categoryCode() === 'side')->map(fn (Product $side) => ['id' => $side->id, 'name' => $side->displayName()])->values()->all()];
        } else {
            $data += ['composition' => $operations->composition($kind, $item),
                'products' => $choices->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->displayName(), 'offer_only' => $product->offer_only, 'available' => $product->available])->values()->all()];
        }
        if ($entry instanceof Offer) {
            $data += ['starts_at' => ($entry->starts_at ?? now())->timezone('Europe/Paris')->format('Y-m-d\TH:i'), 'ends_at' => $entry->ends_at?->timezone('Europe/Paris')->format('Y-m-d\TH:i')];
        }

        return response()->json(['item' => $data, 'categories' => Category::query()->orderBy('position')->get(['id', 'name', 'code']), 'labels' => Category::LABELS]);
    }

    public function save(Request $request, string $kind, AdminOperations $operations, ?int $item = null): JsonResponse
    {
        $entry = $operations->saveItem($request, $kind, $item);

        return response()->json(['id' => $entry->getKey()], $item ? 200 : 201);
    }

    public function archive(string $kind, int $item, AdminOperations $operations): JsonResponse
    {
        $operations->archiveItem($kind, $item);

        return response()->json(['ok' => true]);
    }

    public function saveCategory(Request $request, AdminOperations $operations): JsonResponse
    {
        return response()->json(['id' => $operations->saveCategory($request)->id]);
    }

    public function deleteCategory(Category $category, AdminOperations $operations): JsonResponse
    {
        $operations->deleteCategory($category->id);

        return response()->json(['ok' => true]);
    }

    public function formulas(): JsonResponse
    {
        return response()->json([
            'categories' => collect(DynamicMenuService::CATEGORIES)->map(fn ($code) => ['code' => $code, 'label' => Category::LABELS[$code]])->all(),
            'formulas' => MenuRule::query()->orderBy('position')->orderBy('id')->get()->map(fn (MenuRule $rule) => [
                'id' => $rule->id, 'name' => $rule->name, 'label' => $rule->label(), 'description' => $rule->description, 'price' => $rule->price,
                'categories' => $rule->categories(), 'categories_label' => $rule->categoryLabel(), 'available' => $rule->available, 'visible' => $rule->visible, 'position' => $rule->position,
            ])->values(),
        ]);
    }

    public function saveFormula(Request $request, AdminOperations $operations, ?int $rule = null): JsonResponse
    {
        return response()->json(['id' => $operations->saveRule($request, $rule)->id], $rule ? 200 : 201);
    }
}
