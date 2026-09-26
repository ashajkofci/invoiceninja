<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class MarketingOpportunity extends Model
{
    use HasUuids;
    protected $table = 'marketing_opportunities';
    protected $guarded = [];
    protected $casts = ['consent' => 'boolean', 'automatic' => 'boolean', 'archived' => 'boolean', 'amount' => 'decimal:4'];
    protected $hidden = ['company_id'];
}
