<?php

namespace Vanilo\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Admin\Coupon;

class CartCoupons extends Model
{
	protected $guarded = [
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
	}
}