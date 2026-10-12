<?php

namespace App\Http\Controllers;

use App\Services\CatalogService;
use App\Services\OrderService;
use App\Services\SlotService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffOrderController extends Controller
{
    public function create(Request $request, CatalogService $catalog, SlotService $slots): View|RedirectResponse
    {
        return app(StoreController::class)->cart($request, $catalog, $slots);
    }

    public function store(Request $request, OrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:255',
            'source' => 'required|in:phone,counter',
            'phone' => 'required|string|max:30',
            'slot_id' => 'required|integer|exists:time_slots,id',
            'idempotency_key' => 'required|uuid',
            'expected_total' => 'required|integer|min:0',
            'customer_message' => 'nullable|string|max:1000',
        ]);
        $actor = $request->user();
        abort_unless($actor !== null && $actor->role === 'admin', 403);
        /** @var array<string, int> $cart */
        $cart = $request->session()->get('cart', []);
        $order = $orders->placeForStaff($actor, (int) $data['slot_id'], $data['phone'], $data['idempotency_key'], $cart,
            $data['customer_name'], $data['source'], (int) $data['expected_total'], $data['customer_message'] ?? null);
        $request->session()->forget(['cart', 'checkout_key', 'cart_displayed_total']);

        return redirect()->route('admin.orders.show', $order)->with('success', 'Commande enregistrée. Vous pouvez maintenant enregistrer son encaissement.');
    }
}
