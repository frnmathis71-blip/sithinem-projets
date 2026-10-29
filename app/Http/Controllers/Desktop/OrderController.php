<?php

namespace App\Http\Controllers\Desktop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Services\AdminOperations;
use App\Services\OrderPrintingService;
use App\Services\OrderService;
use App\Support\DesktopPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request, AdminOperations $operations, OrderPrintingService $printing): JsonResponse
    {
        $board = $operations->boardQuery($request);
        $orders = $board['query']->get();
        $day = CarbonImmutable::parse($board['date']);
        $day->locale('fr');

        return response()->json([
            'date' => $board['date'], 'date_label' => $day->translatedFormat('l j F Y'), 'scope' => $board['scope'],
            'orders' => $orders->map(fn (Order $order) => DesktopPresenter::order($order))->values()->all(),
            'printing' => [
                'configured' => $printing->configured(),
                'pending' => DB::table('order_print_jobs')->whereIn('status', ['pending', 'printing'])->count(),
                'issues' => DB::table('order_print_jobs')->whereIn('status', ['failed', 'uncertain'])->orderByDesc('id')->limit(20)->get()
                    ->map(fn ($job) => ['id' => $job->id, 'order_id' => $job->order_id, 'error' => $job->error])->all(),
            ],
        ]);
    }

    /** Live feed: orders created since the last one the application saw, whatever their pickup day. */
    public function recent(Request $request): JsonResponse
    {
        $data = $request->validate(['after' => 'nullable|integer|min:0']);
        $timezone = RestaurantSetting::query()->findOrFail(1)->timezone;
        $orders = isset($data['after']) ? Order::query()->with(['slot', 'items'])->where('id', '>', $data['after'])->orderBy('id')->limit(50)->get() : collect();

        return response()->json([
            'latest_id' => (int) Order::query()->max('id'),
            'orders' => $orders->map(fn (Order $order) => DesktopPresenter::order($order))->values()->all(),
            'preparing_today' => Order::query()->whereIn('status', Order::ACTIVE)->whereHas('slot', fn ($q) => $q->whereDate('date', CarbonImmutable::now($timezone)->format('Y-m-d')))->count(),
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['order' => DesktopPresenter::order($order->load(['slot', 'items']), true)]);
    }

    public function store(Request $request, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:50', 'items.*.key' => ['required', 'string', 'max:3000', 'regex:/^(?:(?:product|offer):\d+|composed:[A-Za-z0-9_-]+)$/'],
            'items.*.quantity' => 'required|integer|min:1|max:50',
            'customer_name' => 'required|string|max:255', 'source' => 'required|in:phone,counter', 'phone' => 'required|string|max:30',
            'slot_id' => 'required|integer|exists:time_slots,id', 'idempotency_key' => 'required|uuid',
            'expected_total' => 'required|integer|min:0', 'customer_message' => 'nullable|string|max:1000',
        ]);
        $actor = $request->user();
        abort_unless($actor !== null, 401);
        $order = $orders->placeForStaff($actor, (int) $data['slot_id'], $data['phone'], $data['idempotency_key'], CartController::cart($data['items']),
            $data['customer_name'], $data['source'], (int) $data['expected_total'], $data['customer_message'] ?? null);

        return response()->json(['order' => DesktopPresenter::order($order->load(['slot', 'items']), true)], 201);
    }

    public function status(Request $request, Order $order, AdminOperations $operations): JsonResponse
    {
        $operations->changeStatus($request, $order);

        return $this->show($order->refresh());
    }

    public function paid(Order $order, AdminOperations $operations): JsonResponse
    {
        $operations->markPaid($order);

        return $this->show($order->refresh());
    }

    public function ticket(Order $order, OrderPrintingService $printing): JsonResponse
    {
        return response()->json(['title' => 'Sithinem #'.$order->id, 'text' => $printing->text($order->load(['slot', 'items']))]);
    }

    public function printToday(OrderPrintingService $printing): JsonResponse
    {
        abort_unless($printing->configured(), 503, 'Le poste d’impression doit être configuré sur le serveur.');

        return response()->json(['count' => $printing->enqueueToday((string) Str::uuid())]);
    }

    public function retryPrint(int $job, OrderPrintingService $printing): JsonResponse
    {
        $printing->retry($job);

        return response()->json(['ok' => true]);
    }
}
