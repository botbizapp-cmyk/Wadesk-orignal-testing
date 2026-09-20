<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id', 'source', 'external_id', 'name', 'category',
        'phone', 'phone_e164', 'email', 'website', 'address', 'lat', 'lng',
        'rating', 'in_crm', 'contact_id', 'raw',
    ];

    protected $casts = [
        'in_crm' => 'boolean',
        'lat'    => 'float',
        'lng'    => 'float',
        'rating' => 'float',
        'raw'    => 'array',
    ];
}
