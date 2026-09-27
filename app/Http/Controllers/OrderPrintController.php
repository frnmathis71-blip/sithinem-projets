<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderPrintingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class OrderPrintController extends Controller
{
    public function enqueue(Request $request, OrderPrintingService $printing): RedirectResponse
    {
        abort_unless($printing->configured(), 503, 'Le poste d’impression doit être configuré.');
        $data = $request->validate(['batch' => 'required|uuid']);
        $count = $printing->enqueueToday($data['batch']);

        return back()->with('success', $count.' ticket(s) ajouté(s) à la file d’impression du jour.');
    }

    public function retry(int $job, OrderPrintingService $printing): RedirectResponse
    {
        $printing->retry($job);

        return back()->with('success', 'Le ticket sera repris par le poste d’impression.');
    }

    public function claim(Request $request, OrderPrintingService $printing): JsonResponse
    {
        $this->authorizeAgent($request, $printing);

        return response()->json(['job' => $printing->claim()])->header('Cache-Control', 'no-store');
    }

    public function acknowledge(Request $request, int $job, OrderPrintingService $printing): JsonResponse
    {
        $this->authorizeAgent($request, $printing);
        $data = $request->validate(['token' => 'required|uuid', 'success' => 'required|boolean', 'error' => 'nullable|string|max:1000']);
        $printing->acknowledge($job, $data['token'], $data['success'], $data['error'] ?? null);

        return response()->json(['ok' => true]);
    }

    private function authorizeAgent(Request $request, OrderPrintingService $printing): void
    {
        abort_unless($printing->configured() && hash_equals((string) config('restaurant.printing.token'), $request->bearerToken() ?? ''), 401);
    }

    public function today(Request $request, OrderPrintingService $printing): Response
    {
        return $this->tickets($request, $printing->today()->get());
    }

    public function show(Request $request, Order $order): Response
    {
        return $this->tickets($request, collect([$order->load(['slot', 'items'])]));
    }

    /** @param Collection<int, Order> $orders */
    private function tickets(Request $request, Collection $orders): Response
    {
        $data = $request->validate(['width' => 'sometimes|integer|in:58,80']);

        return response()->view('restaurant.admin.tickets', [
            'orders' => $orders,
            'width' => (int) ($data['width'] ?? 80),
        ])->header('Cache-Control', 'private, no-store');
    }
}
