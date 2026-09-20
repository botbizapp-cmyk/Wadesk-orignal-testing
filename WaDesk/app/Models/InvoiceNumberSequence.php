<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceNumberSequence extends Model
{
    protected $fillable = ['workspace_id', 'series', 'next_seq'];

    protected $casts = ['next_seq' => 'integer'];
}
