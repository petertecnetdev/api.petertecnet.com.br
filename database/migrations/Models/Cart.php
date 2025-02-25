<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $table = 'carts'; // Specify the table name if it's different from the model name

    protected $fillable = [
        'user_id',
        'idorder',
        'items',
        'code_requests',
        'is_paid',
        'payment_date', // Add the new field to the fillable array
    ];

    protected $casts = [
        'items' => 'json',
        'code_requests' => 'json', // Define the data type of the 'items' field as JSON
        'is_paid' => 'boolean', // Define the data type of the 'is_paid' field as boolean
        'payment_date' => 'datetime', // Define the data type of the 'payment_date' field as datetime
    ];

    // Add any additional methods or relationships to the model as needed
    public function getTotalPrice()
    {
        $totalPrice = 0;

        foreach ($this->items as $item) {
            $totalPrice += $item['quantity'] * $item['price'];
        }

        return $totalPrice;
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

