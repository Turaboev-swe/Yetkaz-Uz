<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesKitchenOrders;
use App\Http\Resources\OrderMessageResource;
use App\Models\OrderMessage;
use App\Services\Chat\OrderChatException;
use App\Services\Chat\OrderChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oshxona paneli: buyurtma chati (/kitchen/orders/{id}/messages…). Ingichka —
 * qoidalar OrderChatService'da. Faqat o'z restorani buyurtmalari (manageableOrder).
 */
class KitchenChatController extends Controller
{
    use AuthorizesKitchenOrders;

    /** Oxirgi xabarlar ro'yxati; ochilganda mijoz xabarlari o'qilgan deb belgilanadi. */
    private const HISTORY_LIMIT = 200;

    public function __construct(private readonly OrderChatService $chat) {}

    /** GET /kitchen/orders/{order}/messages */
    public function index(int $orderId): JsonResponse
    {
        $order = $this->manageableOrder($this->staff(), $orderId);
        $order->load('restaurant:id,name');

        $this->chat->markRead($order);

        $messages = OrderMessage::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        $messages->each(fn (OrderMessage $m) => $m->setRelation('order', $order));

        return response()->json([
            'data' => OrderMessageResource::collection($messages)->resolve(),
            'meta' => ['open' => $this->chat->isOpen($order), 'awaiting_phone' => $this->chat->isAwaitingPhone($order)],
        ]);
    }

    /** POST /kitchen/orders/{order}/messages — {body} yoki {quick}. */
    public function store(Request $request, int $orderId): JsonResponse
    {
        $staff = $this->staff();
        $order = $this->manageableOrder($staff, $orderId);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'required_without:quick'],
            'quick' => ['nullable', Rule::in(OrderChatService::QUICK_REPLIES)],
        ]);

        $message = $this->guard(fn () => $this->chat->sendFromRestaurant($order, $staff, $data['body'] ?? null, $data['quick'] ?? null));

        return $this->created($message, $order);
    }

    /** POST /kitchen/orders/{order}/phone-unreachable — "Raqam ishlamayapti". */
    public function phoneUnreachable(int $orderId): JsonResponse
    {
        $staff = $this->staff();
        $order = $this->manageableOrder($staff, $orderId);

        $message = $this->guard(fn () => $this->chat->requestPhone($order, $staff));

        return $this->created($message, $order);
    }

    /** POST /kitchen/orders/{order}/messages/read — chat ochiq turganda kelgan xabar. */
    public function read(int $orderId): JsonResponse
    {
        $order = $this->manageableOrder($this->staff(), $orderId);

        return response()->json(['data' => ['marked' => $this->chat->markRead($order)]]);
    }

    private function created(OrderMessage $message, $order): JsonResponse
    {
        $message->setRelation('order', $order->loadMissing('restaurant:id,name'));

        return response()->json(['data' => (new OrderMessageResource($message))->resolve()], Response::HTTP_CREATED);
    }

    /** OrderChatException -> HTTP: throttled 429, forbidden 403, qolganlari 422 (xabar o'zbekcha). */
    private function guard(callable $fn): OrderMessage
    {
        try {
            return $fn();
        } catch (OrderChatException $e) {
            $status = match ($e->reason) {
                'throttled' => Response::HTTP_TOO_MANY_REQUESTS,
                'forbidden' => Response::HTTP_FORBIDDEN,
                default => Response::HTTP_UNPROCESSABLE_ENTITY,
            };

            abort(response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $status));
        }
    }
}
