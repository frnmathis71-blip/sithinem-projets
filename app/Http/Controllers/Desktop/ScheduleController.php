<?php

namespace App\Http\Controllers\Desktop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Models\TimeSlot;
use App\Services\AdminOperations;
use App\Services\SlotService;
use App\Services\StatisticsService;
use App\Support\DesktopPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Slots, opening calendar and statistics. */
class ScheduleController extends Controller
{
    public function slots(Request $request, SlotService $slots): JsonResponse
    {
        $request->validate(['date' => 'sometimes|required|date_format:Y-m-d']);
        $settings = RestaurantSetting::query()->findOrFail(1);
        $date = $request->string('date', CarbonImmutable::now($settings->timezone)->format('Y-m-d'))->toString();

        $day = CarbonImmutable::parse($date);
        $day->locale('fr');

        return response()->json(['date' => $date, 'date_label' => $day->translatedFormat('l j F Y'),
            'default_capacity' => $settings->default_amount_capacity, 'hours' => $slots->hours($date), 'slots' => $slots->forDate($date)]);
    }

    public function saveSlot(Request $request, TimeSlot $slot, AdminOperations $operations): JsonResponse
    {
        $operations->saveSlot($request, $slot);

        return response()->json(['ok' => true]);
    }

    public function saveCapacity(Request $request, AdminOperations $operations): JsonResponse
    {
        $operations->saveCapacity($request);

        return response()->json(['ok' => true]);
    }

    public function calendar(Request $request, AdminOperations $operations): JsonResponse
    {
        $calendar = $operations->calendar($request);
        $calendar['month']->locale('fr');

        return response()->json([
            'month' => $calendar['month']->format('Y-m'), 'label' => ucfirst($calendar['month']->translatedFormat('F Y')),
            'previous' => $calendar['month']->subMonth()->format('Y-m'), 'next' => $calendar['month']->addMonth()->format('Y-m'), 'days' => $calendar['days'],
            'weekly' => DB::table('opening_hours')->orderBy('weekday')->get()->map(fn ($day) => ['weekday' => $day->weekday, 'is_open' => (bool) $day->is_open, 'starts_at' => substr($day->starts_at, 0, 5), 'ends_at' => substr($day->ends_at, 0, 5)])->all(),
            'exceptions' => DB::table('opening_exceptions')->orderByDesc('date')->get()->map(fn ($exception) => ['id' => $exception->id, 'date' => $exception->date, 'is_open' => (bool) $exception->is_open, 'starts_at' => substr($exception->starts_at, 0, 5), 'ends_at' => substr($exception->ends_at, 0, 5), 'reason' => $exception->reason])->all(),
        ]);
    }

    public function saveHours(Request $request, AdminOperations $operations): JsonResponse
    {
        $operations->saveHours($request);

        return response()->json(['ok' => true]);
    }

    public function saveException(Request $request, AdminOperations $operations): JsonResponse
    {
        $operations->saveException($request);

        return response()->json(['ok' => true]);
    }

    public function deleteException(int $exception, AdminOperations $operations): JsonResponse
    {
        $operations->deleteException($exception);

        return response()->json(['ok' => true]);
    }

    public function statistics(StatisticsService $statistics): JsonResponse
    {
        $settings = RestaurantSetting::query()->findOrFail(1);
        $today = now($settings->timezone)->format('Y-m-d');
        $waiting = Order::query()->with(['slot', 'items'])->whereIn('status', Order::ACTIVE)->whereHas('slot', fn (Builder $q) => $q->whereDate('date', $today))->get()
            ->sortBy(fn (Order $order) => $order->slot->starts_at)->map(fn (Order $order) => DesktopPresenter::order($order))->values();

        return response()->json(['report' => $statistics->report($settings->timezone), 'waiting' => $waiting,
            'failed_notifications' => DB::table('notification_outbox')->whereNull('sent_at')->whereNotNull('last_error')->count()]);
    }
}
