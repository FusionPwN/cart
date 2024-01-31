<?php

namespace Vanilo\Cart\Traits;

use App\Models\Admin\Order;
use App\Models\Admin\OrderItem;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Vanilo\Adjustments\Adjusters\DirectDiscount;
use Vanilo\Adjustments\Adjusters\DiscountInterval;
use Vanilo\Adjustments\Adjusters\DiscountStore;
use Vanilo\Adjustments\Contracts\AdjustmentType;
use Vanilo\Adjustments\Models\Adjustment;
use Vanilo\Adjustments\Models\AdjustmentTypeProxy;
use Vanilo\Cart\Models\Cart;
use Illuminate\Support\Str;
use Vanilo\Cart\Models\CartItem;
use Vanilo\Contracts\Buyable;

trait CheckoutItemFunctions
{
	public Object $prices;

	public function total(): float
	{
		if ($this instanceof OrderItem) {
			if ($this->order->isEditable()) {
				return (float) $this->itemsTotal();
			} else {
				return $this->price * $this->quantity;
			}
		} else if ($this instanceof CartItem) {
			return (float) $this->itemsTotal();
		}
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
	public function getAdjustedPrice(array $excludes = []): float
	{
		if ($this instanceof OrderItem) {
			$price = $this->mod_price != 0 ? $this->mod_price : $this->product->getPriceVat();
		} else {
			$price = $this->product->getPriceVat();
		}

		$adjustments = $this->adjustments()->getIterator();

		if (isset($adjustments)) {
			foreach ($adjustments as $adjustment) {
				if (!AdjustmentTypeProxy::IsVisualSeparator($adjustment->type) && !in_array($adjustment->type, $excludes)) {
					$price -= (float) $adjustment->getData('single_amount');
				}
			}
		}

		return $price;
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

	public function updateIntervalAdjustments(mixed $adjustable)
	{
		if (!$adjustable instanceof Cart && !$adjustable instanceof Order) {
			throw new Exception(
				sprintf(
					'Argument must be an instance of %s or %s, %s given',
					Cart::class,
					Order::class,
					$adjustable::class
				)
			);
		}

		$intervalAdjustment = $this->adjustments()->byType(AdjustmentTypeProxy::INTERVAL_DISCOUNT())->first();

		if (isset($intervalAdjustment)) {
			$this->removeAdjustment($intervalAdjustment);
		}

		$price_interval = $this->product->getInterval($this->quantity());

		if ($price_interval) {
			$this->adjustments()->create(new DiscountInterval($adjustable, $this, $price_interval));
		}
	}

	public function updateStoreDiscountAdjustments(mixed $adjustable)
	{
		if (!$adjustable instanceof Cart && !$adjustable instanceof Order) {
			throw new Exception(
				sprintf(
					'Argument must be an instance of %s or %s, %s given',
					Cart::class,
					Order::class,
					$adjustable::class
				)
			);
		}

		$store_discount = (float) Cache::get('settings.store_discount');
		$storeAdjustment = $this->adjustments()->byType(AdjustmentTypeProxy::STORE_DISCOUNT())->first();

		if (isset($storeAdjustment)) {
			$this->removeAdjustment($storeAdjustment);
		}

		if ($store_discount > 0) {
			if ($this->product->no_apply_store_discount == 1 || (Cache::get('settings.campaign_ignore_store_discount') == 1 && (count($this->product->discountTreeWithTypePivot) > 0 || $this->product->validDirectDiscount()))) {
				# NAO APLICA DESCONTO LOJA PQ TEM CAPANHAS
			} else {
				$this->adjustments()->create(new DiscountStore($adjustable, $this, $store_discount));
			}
		}
	}

	public function updateDirectDiscountAdjustments(mixed $adjustable)
	{
		if (!$adjustable instanceof Cart && !$adjustable instanceof Order) {
			throw new Exception(
				sprintf(
					'Argument must be an instance of %s or %s, %s given',
					Cart::class,
					Order::class,
					$adjustable::class
				)
			);
		}

		$directAdjustment = $this->adjustments()->byType(AdjustmentTypeProxy::DIRECT_DISCOUNT())->first();

		if (isset($directAdjustment)) {
			$this->removeAdjustment($directAdjustment);
		}

		if ($this->product->validDirectDiscount() && (count($this->product->discountTreeWithTypePivot) == 0 || (count($this->product->discountTreeWithTypePivot) > 0 && $this->product->discountTreeWithTypePivot->first()->can_stack_direct_discount == 1))) {
			$this->adjustments()->create(new DirectDiscount($adjustable, $this));
		}
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
	 * @inheritDoc
	 */
	public function setQuantity(int $value = 1)
	{
		$this->quantity = $value;
		$this->save();
	}

	/**
	 * Property accessor alias to the getAdjustedPrice() method
	 *
	 * @return float
	 */
	public function getAdjustedPriceAttribute()
	{
		return $this->getAdjustedPrice();
	}

	public function getCampaignAdjustments(): Collection
	{
		$adjustments = collect();

		foreach ($this->adjustments()->getIterator() as $adjustment) {
			if (AdjustmentTypeProxy::IsCampaignDiscount($adjustment->type)) {
				$adjustments->add($adjustment);
			}
		}

		return $adjustments;
	}

	public function getCouponAdjustments(): Collection
	{
		$adjustments = collect();

		foreach ($this->adjustments()->getIterator() as $adjustment) {
			if (AdjustmentTypeProxy::IsCoupon($adjustment->type)) {
				$adjustments->add($adjustment);
			}
		}

		return $adjustments;
	}
}
