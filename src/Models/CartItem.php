<?php

declare(strict_types=1);

namespace Vanilo\Cart\Models;

use Vanilo\Adjustments\Adjusters\DiscountInterval;
use Vanilo\Adjustments\Adjusters\DiscountStore;
use Vanilo\Cart\Contracts\CartItem as CartItemContract;
use Vanilo\Contracts\Buyable;
use App\Models\Admin\Product;
use App\Models\Traits\ProductItem;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Vanilo\Adjustments\Contracts\Adjustable;
use Vanilo\Adjustments\Contracts\AdjustmentType;
use Vanilo\Adjustments\Models\Adjustment;
use Vanilo\Adjustments\Models\AdjustmentTypeProxy;
use Vanilo\Adjustments\Support\HasAdjustmentsViaRelation;
use Vanilo\Adjustments\Support\RecalculatesAdjustments;

/**
 * @property Buyable $product
 * @property float   $total
 */
class CartItem extends Model implements CartItemContract, Adjustable
{
	use HasAdjustmentsViaRelation;
	use RecalculatesAdjustments;
	use ProductItem;

	protected $guarded = ['id', 'created_at', 'updated_at'];

	public Object $prices;

	public static function boot()
	{
		parent::boot();

		# converter valores decimal em floats, eram carregados como string...
		static::retrieved(function ($model) {
			$model->price_vat = (float) $model->price_vat;
			$model->prices = $model->formattedPrice();
		});

		static::deleting(function ($model) {
			$model->removeAllAdjustments();
		});
	}

	public function total(): float
	{
		return (float) $this->itemsTotal();
	}

	public function itemsTotal(): float
	{
		return (float) ($this->getAdjustedPrice() * $this->quantity());
	}

	public function adjustmentsTotal(): float
	{
		$adj_total = 0;

		foreach ($this->adjustments()->getIterator() as $adjustment) {
			if (!AdjustmentTypeProxy::IsVisualSeparator($adjustment->type)) {
				$adj_total += $adjustment->getAmount();
			}
		}

		return (float) $adj_total;
	}

	public function quantity(): int
	{
		$adj_quantity = 0;

		foreach ($this->adjustments()->getIterator() as $adjustment) {
			if ($adjustment->type == AdjustmentTypeProxy::OFERTA_BARATO()) {
				$adj_quantity += $adjustment->getData('quantity') ?? 0;
			}
		}

		return (int) $this->quantity - $adj_quantity;
	}

	public function vatTotal(): float
	{
		return (float) $this->itemVatTotal();
	}

	public function itemVatTotal(): float
	{
		# PVP - (PVP / (1 + (IVA / 100)))
		return (float) $this->total() - ($this->total() / (1 + $this->product->getVat()));
	}

	/**
	 * Preco unitário com descontos
	 *
	 * @return float
	 */
	public function getAdjustedPrice(): float
	{
		$price = $this->product->getPriceVat();
		$adjustments = $this->adjustments()->getIterator();

		if (isset($adjustments)) {
			foreach ($adjustments as $adjustment) {
				if (!AdjustmentTypeProxy::IsVisualSeparator($adjustment->type)) {
					$price -= (float) $adjustment->getData('single_amount');
				}
			}
		}

		return $price;
	}

	public function weight(): float
	{
		return (float) $this->product->net_weight * $this->quantity();
	}

	public function product()
	{
		return $this->hasOne(Product::class, 'id', 'product_id');
		//return $this->morphTo();
	}

	public function product_discount()
	{
		return $this->hasOne(Product::class, 'id', 'product_id')->with('discountTree');
	}

	/**
	 * @inheritDoc
	 */
	public function getBuyable(): Buyable
	{
		return $this->product;
	}

	/**
	 * @inheritDoc
	 */
	public function getQuantity(): int
	{
		return (int) $this->quantity();
	}

	/**
	 * Check if Product prevents free shipping
	 *
	 * @return bool
	 */
	public function preventsFreeShipping(): bool
	{
		return $this->product->preventsFreeShipping();
	}

	/**
	 * Property accessor alias to the total() method
	 *
	 * @return float
	 */
	public function getTotalAttribute()
	{
		return $this->total();
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

	/**
	 * Scope to query items by product (Buyable)
	 *
	 * @param Builder $query
	 * @param Buyable $product
	 *
	 * @return Builder
	 */
	public function scopeByProduct($query, Buyable $product)
	{
		return $query->where([
			['product_id', '=', $product->getId()],
			['product_type', '=', $product->morphTypeName()]
		]);
	}

	public function getAdjustmentByType(AdjustmentType $type = null)
	{
		if (!isset($type)) {
			throw new Exception(
				sprintf(
					'Adjustment type is empty. Please provide a valid value.',
					''
				)
			);
		}
		return $this->adjustments()->byType($type)->first();
	}

	public function removeAdjustment(Adjustment $adjustment = null, AdjustmentType $type = null)
	{
		if (isset($type)) {
			$type = Str::upper($type->value());

			$adjustment = $this->adjustments()->byType(AdjustmentTypeProxy::$type())->first();

			if (isset($adjustment)) {
				$this->removeAdjustment($adjustment);
			}
		} else {
			$this->adjustments()->remove($adjustment);
		}
	}

	public function removeAllAdjustments()
	{
		$adjustments = $this->adjustments()->getIterator();

		foreach ($adjustments as $adjustment) {
			$this->removeAdjustment($adjustment);
		}
	}

	public function updateIntervalAdjustments(?Cart $cart = null)
	{
		$intervalAdjustment = $this->adjustments()->byType(AdjustmentTypeProxy::INTERVAL_DISCOUNT())->first();

		if (isset($intervalAdjustment)) {
			$this->removeAdjustment($intervalAdjustment);
		}

		$price_interval = $this->product->getInterval($this->quantity());

		if ($price_interval) {
			$this->adjustments()->create(new DiscountInterval($cart, $this, $price_interval));
		}
	}

	public function updateStoreDiscountAdjustments(?Cart $cart = null)
	{
		$store_discount = (float) Cache::get('settings.store_discount');
		$storeAdjustment = $this->adjustments()->byType(AdjustmentTypeProxy::STORE_DISCOUNT())->first();

		if (isset($storeAdjustment)) {
			$this->removeAdjustment($storeAdjustment);
		}

		if ($store_discount > 0) {
			if (Cache::get('settings.campaign_ignore_store_discount') == 1 && count($this->product->discountTree) > 0) {
				# NAO APLICA DESCONTO LOJA PQ TEM CAPANHAS
			} else {
				$this->adjustments()->create(new DiscountStore($cart, $this, $store_discount));
			}
		}
	}
}
