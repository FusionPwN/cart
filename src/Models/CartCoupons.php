<?php

namespace Vanilo\Cart\Models;

use App\Models\Admin\Coupon;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CartCoupons extends Pivot
{
	public $incrementing = true;
	
	/* protected $guarded = [
		'id',
		'created_at',
		'updated_at'
	];

    public function coupon()
	{
        return $this->hasOne(Coupon::class, 'id', 'coupon_id');
    }

	public function cart()
	{
		return $this->hasOne(Cart::class, 'id', 'cart_id');
	} */
}