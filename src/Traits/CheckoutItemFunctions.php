<?php

namespace Vanilo\Cart\Traits;

use App\Models\Admin\Order;
use Exception;
use Illuminate\Support\Facades\Cache;
use Vanilo\Adjustments\Adjusters\DirectDiscount;
use Vanilo\Adjustments\Adjusters\DiscountInterval;
use Vanilo\Adjustments\Adjusters\DiscountStore;
use Vanilo\Adjustments\Contracts\AdjustmentType;
use Vanilo\Adjustments\Models\Adjustment;
use Vanilo\Adjustments\Models\AdjustmentTypeProxy;
use Vanilo\Cart\Models\Cart;
use Illuminate\Support\Str;
use Vanilo\Contracts\Buyable;

trait CheckoutItemFunctions
{
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
		if (!$adjustable instanceof Cart || !$adjustable instanceof Order) {
			throw new Exception(
				sprintf(
					'Argument must be an instance of %s or %s',
					Cart::class,
					Order::class
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

	public function updateDirectDiscountAdjustments(?Cart $cart = null)
	{
		$directAdjustment = $this->adjustments()->byType(AdjustmentTypeProxy::DIRECT_DISCOUNT())->first();

		if (isset($directAdjustment)) {
			$this->removeAdjustment($directAdjustment);
		}

		if ($this->product->validDirectDiscount()) {
			$this->adjustments()->create(new DirectDiscount($cart, $this));
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
}
