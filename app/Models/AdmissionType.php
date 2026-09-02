<?php

namespace App\Models;

use App\Models\Traits\BelongsToApplicationContext;
use Illuminate\Database\Eloquent\Model;

class AdmissionType extends Model
{
    use BelongsToApplicationContext;

    protected $fillable = [
        'application_id','event_id','item_id','name','status','price','capacity',
        'sales_start_at','sales_end_at','legacy_source_type','legacy_source_id','metadata',
    ];

    protected $casts = [
        'price'=>'decimal:2','capacity'=>'integer','sales_start_at'=>'datetime',
        'sales_end_at'=>'datetime','metadata'=>'array',
    ];

    protected function applicationContextColumn(): string
    {
        return 'application_id';
    }

    public function application() { return $this->belongsTo(Application::class); }
    public function event() { return $this->belongsTo(Event::class); }
    public function item() { return $this->belongsTo(Item::class); }
    public function credentials() { return $this->hasMany(AdmissionCredential::class); }
}
