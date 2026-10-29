<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Menu;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantSetting;
use App\Models\TimeSlot;
use App\Services\AdminOperations;
use App\Services\CatalogService;
use App\Services\OrderPrintingService;
use App\Services\OrderService;
use App\Services\SlotService;
use App\Services\StatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard(Request $request, StatisticsService $statistics): View
    {
        if (! $request->routeIs('admin.statistics')) {
            return $this->orders($request);
        }
        $settings = RestaurantSetting::query()->findOrFail(1);

        return view('restaurant.admin.dashboard', ['report' => $statistics->report($settings->timezone), 'orders' => Order::query()->with('slot')->whereIn('status', Order::ACTIVE)->whereHas('slot', fn (Builder $q) => $q->whereDate('date', now($settings->timezone)->format('Y-m-d')))->get(), 'pushConfigured' => (bool) config('restaurant.vapid.public_key'), 'failedNotifications' => DB::table('notification_outbox')->whereNull('sent_at')->whereNotNull('last_error')->count()]);
    }

    public function orderFeed(Request $request): JsonResponse
    {
        $board = $this->boardQuery($request);
        $orders = $board['query']->get();
        $html = view('restaurant.admin.order-cards', ['orders' => $orders, 'scope' => $board['scope']])->render();
        $day = CarbonImmutable::parse($board['date']);
        $day->locale('fr');

        return response()->json(['preparing' => $orders->whereIn('status', Order::ACTIVE)->count(), 'date' => $board['date'], 'date_label' => $day->translatedFormat('l j F Y'), 'html' => $html, 'signature' => hash('sha256', $html)])->header('Cache-Control', 'no-store');
    }

    public function orders(Request $request): View
    {
        $board = $this->boardQuery($request);

        return view('restaurant.admin.board', ['orders' => $board['query']->get(), 'date' => $board['date'], 'scope' => $board['scope'], 'pushConfigured' => (bool) config('restaurant.vapid.public_key'),
            'printingConfigured' => app(OrderPrintingService::class)->configured(),
            'printIssues' => DB::table('order_print_jobs')->whereIn('status', ['failed', 'uncertain'])->orderByDesc('id')->limit(20)->get(),
            'printPending' => DB::table('order_print_jobs')->whereIn('status', ['pending', 'printing'])->count(),
        ]);
    }

    /** @return array{query: Builder<Order>, date: string, scope: string} */
    private function boardQuery(Request $request): array
    {
        return app(AdminOperations::class)->boardQuery($request);
    }

    public function status(Request $request, Order $order): RedirectResponse
    {
        $status = app(AdminOperations::class)->changeStatus($request, $order);

        return back()->with('success', $status === 'completed' ? 'Commande terminée et déplacée dans l’historique.' : 'Commande passée à « '.Order::STATUSES[$status].' ».');
    }

    public function addItem(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['item' => 'required|string', 'quantity' => 'required|integer|min:1|max:50']);
        app(OrderService::class)->addItem($order, $request->user(), $data['item'], (int) $data['quantity']);

        return back()->with('success', 'Article ajouté. Nouveau total : '.number_format($order->fresh()->total / 100, 2, ',', ' ').' €.');
    }

    public function removeItem(Request $request, Order $order, int $item): RedirectResponse
    {
        app(OrderService::class)->removeItem($order, $request->user(), $item);

        return back()->with('success', 'Article retiré. Nouveau total : '.number_format($order->fresh()->total / 100, 2, ',', ' ').' €.');
    }

    public function reschedule(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['slot_id' => 'required|integer|exists:time_slots,id']);
        $changed = app(OrderService::class)->reschedule($order, $request->user(), (int) $data['slot_id']);

        return redirect()->route('admin.orders.show', $order)->with('success', $changed ? 'Heure de retrait modifiée. Le client est prévenu.' : 'La commande était déjà sur ce créneau.');
    }

    public function paid(Order $order): RedirectResponse
    {
        app(AdminOperations::class)->markPaid($order);

        return back()->with('success', 'Paiement sur place enregistré.');
    }

    public function slots(Request $request, SlotService $slots): View
    {
        $request->validate(['date' => 'sometimes|required|date_format:Y-m-d']);
        $settings = RestaurantSetting::query()->findOrFail(1);
        $date = $request->string('date', CarbonImmutable::now($settings->timezone)->format('Y-m-d'))->toString();

        return view('restaurant.admin.slots', ['settings' => $settings, 'date' => $date, 'slots' => $slots->forDate($date), 'hours' => $slots->hours($date)]);
    }

    public function saveSlot(Request $request, TimeSlot $slot): RedirectResponse
    {
        app(AdminOperations::class)->saveSlot($request, $slot);

        return back()->with('success', 'Créneau mis à jour. Toutes les commandes existantes sont conservées.');
    }

    public function settings(Request $request): RedirectResponse
    {
        app(AdminOperations::class)->saveCapacity($request);

        return back()->with('success', 'Capacité par défaut mise à jour pour les créneaux sans personnalisation.');
    }

    public function contact(): View
    {
        return view('restaurant.admin.contact', ['settings' => RestaurantSetting::query()->findOrFail(1)]);
    }

    public function saveContact(Request $request): RedirectResponse
    {
        $data = $request->validate(['contact_phone' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9 .()\-]{7,24}$/']], ['contact_phone.regex' => 'Saisissez un numéro de téléphone valide, par exemple 01 23 45 67 89.']);
        RestaurantSetting::query()->whereKey(1)->update(['contact_phone' => isset($data['contact_phone']) ? trim($data['contact_phone']) : null]);

        return back()->with('success', 'Coordonnées enregistrées. Elles sont visibles sur la page Contact.');
    }

    public function calendar(Request $request): View
    {
        $calendar = app(AdminOperations::class)->calendar($request);

        return view('restaurant.admin.calendar', ['weekly' => DB::table('opening_hours')->orderBy('weekday')->get(), 'exceptions' => DB::table('opening_exceptions')->orderByDesc('date')->get(), ...$calendar]);
    }

    public function saveHours(Request $request): RedirectResponse
    {
        app(AdminOperations::class)->saveHours($request);

        return back()->with('success', 'Horaires enregistrés. Vérifiez les commandes existantes si vous avez réduit les horaires.');
    }

    public function saveException(Request $request): RedirectResponse
    {
        app(AdminOperations::class)->saveException($request);

        return back()->with('success', 'Exception enregistrée. Les commandes sont conservées ; consultez les créneaux de cette date.');
    }

    public function deleteException(int $exception): RedirectResponse
    {
        app(AdminOperations::class)->deleteException($exception);

        return back()->with('success', 'Les horaires habituels s’appliquent à nouveau.');
    }

    public function card(): View
    {
        return view('restaurant.admin.card', ['categories' => Category::query()->orderBy('position')->orderBy('id')->get(), 'products' => Product::query()->with('variants')->whereNull('parent_id')->orderBy('name')->get(), 'menus' => Menu::query()->orderBy('name')->get(), 'offers' => Offer::query()->orderBy('name')->get()]);
    }

    public function catalog(): RedirectResponse
    {
        return redirect()->route('admin.card');
    }

    public function editItem(string $kind, CatalogService $catalog, ?int $item = null): View|RedirectResponse
    {
        if ($kind === 'menu') {
            return redirect()->route('admin.menu-rules');
        }
        $entry = $item ? $catalog->model($kind)::query()->findOrFail($item) : new ($catalog->model($kind));
        abort_if($entry instanceof Product && $entry->parent_id !== null, 404);
        $operations = app(AdminOperations::class);

        return view('restaurant.admin.item', ['kind' => $kind, 'item' => $entry, 'categories' => Category::query()->orderBy('position')->get(), 'products' => $operations->compositionProducts($kind)->get(), 'composition' => $operations->composition($kind, $item)]);
    }

    public function saveItem(Request $request, string $kind, ?int $item = null): RedirectResponse
    {
        if ($kind === 'menu') {
            return redirect()->route('admin.menu-rules');
        }
        app(AdminOperations::class)->saveItem($request, $kind, $item);

        return redirect()->route('admin.card')->with('success', 'Article enregistré.');
    }

    public function archiveItem(string $kind, int $item): RedirectResponse
    {
        app(AdminOperations::class)->archiveItem($kind, $item);

        return redirect()->route('admin.card')->with('success', 'Article archivé. Les anciennes commandes sont conservées.');
    }

    public function categories(): RedirectResponse
    {
        return redirect()->to(route('admin.card').'#categories');
    }

    public function saveCategory(Request $request): RedirectResponse
    {
        app(AdminOperations::class)->saveCategory($request);

        return back()->with('success', 'Catégorie enregistrée.');
    }

    public function deleteCategory(Category $category): RedirectResponse
    {
        app(AdminOperations::class)->deleteCategory($category->id);

        return redirect()->to(route('admin.card').'#categories')->with('success', 'Catégorie supprimée. Ses produits sont conservés dans « Sans catégorie » et désactivés. Reclassez-les avant de les remettre en vente.');
    }
}
