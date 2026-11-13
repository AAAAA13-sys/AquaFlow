<?php

namespace App\Http\Requests;

/** A draft edit replaces the whole editable content, so it follows the create rules. */
class UpdatePurchaseOrderRequest extends StorePurchaseOrderRequest
{
}
