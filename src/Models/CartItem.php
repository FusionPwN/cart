<?php

declare(strict_types=1);

namespace Vanilo\Cart\Models;

use App\Models\Admin\Prescription;
use Vanilo\Adjustments\Adjusters\DiscountInterval;
use Vanilo\Adjustments\Adjusters\DiscountStore;
use Vanilo\Adjustments\Adjusters\DirectDiscount;
use Vanilo\Cart\Contracts\CartItem as CartItemContract;
use Vanilo\Contracts\Buyable;
use App\Models\Admin\Product;
use App\Models\Admin\ShipmentMethod;
use App\Models\Admin\ZoneGroup;
use App\Models\Traits\ProductItem;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Vanilo\Adjustments\Contracts\Adjustable;
use Vanilo\Adjustments\Contracts\AdjustmentType;
use Vanilo\Adjustments\Models\Adjustment;
use Vanilo\Cart\Traits\CheckoutItemFunctions;
use Vanilo\Cart\Traits\HasModifiers;

/**
 * @property Buyable $product
 * @property float   $total
 */
class CartItem extends Model implements CartItemContract, Adjustable
{
	use HasModifiers;
	use ProductItem;
	use CheckoutItemFunctions;

	protected $guarded = ['id', 'created_at', 'updated_at'];

	protected $casts = [
		'properties' => 'object',
	];

	public $display_quantity;
	public bool $can_change_quantity = true;
	public bool $out_of_stock = false;
	public bool $missing_units = false;

	public static function boot()
	{
		parent::boot();

		# converter valores decimal em floats, eram carregados como string...
		static::retrieved(function ($model) {
			$model->cartItemInit();
		});

		/* static::deleting(function ($model) {
			$model->removeAllAdjustments();
		}); */
	}

	public function cart()
	{
		return $this->belongsTo(Cart::class);
	}

	public function cartItemInit()
	{
		$this->price_vat = (float) $this->price_vat;
		$this->refreshPricesAttribute();
	}

	public function weight(?ShipmentMethod $shipmentMethod = null, ?ZoneGroup $zoneGroup = null): float
	{
		return (float) $this->product->weight($shipmentMethod, $zoneGroup) * $this->quantity();
	}

	public function product()
	{
		return $this->hasOne(Product::class, 'id', 'product_id');
		#return $this->morphTo(/* __FUNCTION__, 'product_type', 'product_id' */);
	}

	public function prescription()
	{
		return $this->hasOne(Prescription::class, 'id', 'product_id');
	}

	/**
	 * Scope to query items of a cart
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param mixed $cart Cart object or cart id
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeOfCart($query, $cart)
	{
		$cartId = is_object($cart) ? $cart->id : $cart;

		return $query->where('cart_id', $cartId);
	}

	/**
	 * Scope for returning the products with active state
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeActives($query)
	{
		return $query->whereHas('product', function ($query) {
			$query->actives();
		});
	}

	/**
	 * Scope for returning the products with active state
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeListableInverse($query)
	{
		return $query->whereHas('product', function ($query) {
			$query->listableInverse();
		});
	}

	/**
	 * Scope for returning the products that have an above 0 stock value
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeHasStock($query)
	{
		return $query->whereHas('product', function ($query) {
			$query->hasStock();
		});
	}
}
