<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PowerProvider extends Model
{
  protected $hidden = ['created_at', 'updated_at', 'code'];
  protected $fillable = [
    'name',
    'code',
    'max_purchase',
    'min_purchase'
  ];
}