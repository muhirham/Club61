<?php

namespace App\Services\Fnb;

use App\Models\Pos\Order;
use App\Services\Payment\Contracts\DomainFulfillmentHandlerInterface;
use Illuminate\Support\Collection;

/** Order F&B lunas → slip pesanan untuk tiap stasiun yang punya menu di order ini (idempoten). */
class FnbFulfillmentHandler implements DomainFulfillmentHandlerInterface
{
    public function __construct(private StationTicketService $tickets) {}

    public function fulfill(Order $order, Collection $items): void
    {
        $this->tickets->createForOrder($order);
    }
}
