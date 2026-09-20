<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceWebhookEvent extends Model
{
    protected $fillable = ['source', 'delivery_id', 'topic', 'external_order_id', 'received_at'];

    protected $casts = ['received_at' => 'datetime'];
}
