<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class MarketingActivity extends Model
{
    use HasUuids;
    protected $table = 'marketing_activities';
    protected $guarded = [];
    protected $casts = ['snapshot' => 'array'];
    protected $hidden = ['company_id'];
}
