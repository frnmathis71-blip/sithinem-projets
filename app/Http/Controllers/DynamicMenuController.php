<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\MenuRule;
use App\Models\Product;
use App\Services\CatalogManagementService;
use App\Services\CatalogService;
use App\Services\DynamicMenuService;
use App\Services\RestaurantLock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DynamicMenuController extends Controller
{
    public function index(): View
    {
        return view('restaurant.formulas', ['rules' => MenuRule::query()->where('available', true)->where('visible', true)->orderBy('position')->orderBy('id')->get(), 'categories' => Category::query()->orderBy('position')->get()]);
    }

    public function show(MenuRule $rule, DynamicMenuService $menus): View
    {
        abort_unless($rule->available && $menus->validCategories($rule->categories()), 404);

        return $this->selectionPage($menus, $rule);
    }

    public function builder(Request $request, DynamicMenuService $menus): View|RedirectResponse
    {
        $request->validate(['product' => 'nullable|integer|exists:products,id']);
        if (! $request->filled('product')) {
            return redirect()->route('menus.index');
        }
        $product = Product::query()->findOrFail($request->integer('product'));
        abort_unless($product->requiresSide() && app(CatalogService::class)->productAvailable($product), 404);

        return $this->selectionPage($menus, null, $product);
    }

    private function selectionPage(DynamicMenuService $menus, ?MenuRule $rule, ?Product $product = null): View
    {
        $steps = $rule ? $rule->steps() : ['side'];
        $options = array_values(array_filter($menus->options(), fn ($option) => in_array($option['code'], $steps, true) && (! $product || in_array($option['id'], $menus->sides($product), true))));

        return view('restaurant.builder', ['options' => $options, 'rule' => $rule, 'product' => $product, 'steps' => $steps, 'categories' => Category::query()->orderBy('position')->get()]);
    }

    public function add(Request $request, DynamicMenuService $menus): RedirectResponse
    {
        $data = $request->validate(['rule_id' => 'nullable|integer|min:1', 'selections' => 'required|array:starter,main,chef_main,side,dessert,drink', 'selections.*' => 'nullable|integer|min:1']);
        $key = $menus->key(array_map('intval', array_filter($data['selections'], fn ($id) => $id !== null)), isset($data['rule_id']) ? (int) $data['rule_id'] : null);
        $menus->quote($key, 1);
        $cart = $request->session()->get('cart', []);
        abort_if(count($cart) >= 50, 422, 'Le panier contient trop de références.');
        $cart[$key] = 1;
        $this->saveCart($request, $cart);

        return redirect()->route('cart')->with('success', 'Votre composition a été ajoutée.');
    }

    public function proposal(Request $request, DynamicMenuService $menus): RedirectResponse
    {
        $data = $request->validate(['signature' => 'required|string|size:64', 'revision' => 'required|string|size:64', 'action' => 'required|in:accept,decline']);
        $cart = $request->session()->get('cart', []);
        $refused = $request->session()->get('menu_refused', []);
        if ($data['action'] === 'decline') {
            $request->session()->put('menu_refused', array_slice(array_unique([...$refused, $data['signature']]), -100));

            return back();
        }
        $proposal = $menus->suggestion($cart, $refused);
        if ($data['revision'] !== self::revision($cart) || ! $proposal || $proposal['signature'] !== $data['signature']) {
            return back()->withErrors(['cart' => 'Le panier ou le tarif a changé. Vérifiez la nouvelle proposition.']);
        }
        foreach ($proposal['keys'] as $key) {
            if (--$cart[$key] === 0) {
                unset($cart[$key]);
            }
        }
        $cart[$proposal['key']] = 1;
        $this->saveCart($request, $cart);

        return back()->with('success', 'Vos produits ont été regroupés en menu.');
    }

    /** @param array<string, int> $cart */
    public static function revision(array $cart): string
    {
        return hash('sha256', json_encode($cart, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, int> $cart */
    private function saveCart(Request $request, array $cart): void
    {
        $request->session()->put('cart', $cart);
        $request->session()->put('checkout_key', (string) Str::uuid());
    }

    public function admin(): View
    {
        return view('restaurant.admin.menu-rules', ['rules' => MenuRule::query()->orderBy('position')->orderBy('id')->get()]);
    }

    public function editRule(?int $rule = null): View
    {
        return view('restaurant.admin.menu-rule', ['rule' => $rule ? MenuRule::query()->findOrFail($rule) : new MenuRule]);
    }

    public function saveRule(Request $request, DynamicMenuService $menus, RestaurantLock $lock, CatalogManagementService $catalog, ?int $rule = null): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'categories' => 'required|array|min:1|max:5', 'categories.*' => ['required', 'string', 'distinct', Rule::in(DynamicMenuService::CATEGORIES)], 'price' => ['required', 'regex:/^\d{1,5}([.,]\d{1,2})?$/'], 'available' => 'required|boolean', 'visible' => 'required|boolean', 'position' => 'required|integer|min:0|max:999']);
        if (! $menus->validCategories($data['categories'])) {
            throw ValidationException::withMessages(['categories' => 'Une formule propose soit un plat classique, soit un plat du chef, jamais les deux.']);
        }
        $lock->run(function () use ($data, $catalog, $menus, $rule) {
            $entry = $rule ? MenuRule::query()->findOrFail($rule) : new MenuRule;
            $entry->fill(['name' => $data['name'], 'description' => $data['description'] ?? null, 'category_key' => $menus->categoryKey($data['categories']), 'price' => $catalog->cents($data['price']), 'available' => $data['available'], 'visible' => $data['visible'], 'position' => $data['position'], 'version' => $entry->exists ? $entry->version + 1 : 1])->save();
        });

        return redirect()->route('admin.menu-rules')->with('success', 'Formule enregistrée.');
    }
}
