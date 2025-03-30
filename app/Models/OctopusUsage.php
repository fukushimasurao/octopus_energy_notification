<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OctopusUsage extends Model
{
    protected $fillable = [
        'date',
        'kwh',
        'estimated_cost',
    ];
}
