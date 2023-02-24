<?php

namespace Vanilo\Cart\Traits;

use App\Classes\Utilities;
use App\Models\Admin\Card;
use App\Models\Admin\Coupon;
use App\Models\Admin\CouponType;
use App\Models\Admin\Order;
use App\Models\Admin\OrderCoupon;
use App\Models\Admin\ShipmentMethod;
use App\Rules\Coupon\CanBeUsedInZone;
use App\Rules\Coupon\CanBeUsedWithDiscounts;
use App\Rules\Coupon\CanBeUsedWithProducts;
use App\Rules\Coupon\HasUsesLeft;
use App\Rules\Coupon\IsCouponActive;
use App\Rules\Coupon\IsCouponExpired;
use App\Rules\Coupon\IsStartDateValid;
use App\Rules\Coupon\IsUserAllowed;
use App\Rules\Coupon\IsValidShippingAdjustment;
use App\Rules\Coupon\OrderHasMinValue;
use App\Rules\Coupon\ProductsAllowFreeShipping;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Konekt\Address\Models\Country;
use Vanilo\Adjustments\Adjusters\ClientCard;
use Vanilo\Adjustments\Adjusters\DiscountFree;
use Vanilo\Adjustments\Adjusters\DiscountLeastExpensiveFree;
use Vanilo\Adjustments\Adjusters\DiscountPercNum;
use Vanilo\Adjustments\Adjusters\DiscountSameFree;
use Vanilo\Adjustments\Adjusters\DiscountScalablePercNum;
use Vanilo\Adjustments\Adjusters\SimpleShippingFee;
use Vanilo\Adjustments\Contracts\AdjustmentType;
use Vanilo\Adjustments\Models\Adjustment;
use Vanilo\Adjustments\Models\AdjustmentTypeProxy;
use Illuminate\Support\Str;
use Vanilo\Adjustments\Adjusters\CouponFreeShipping;
use Vanilo\Adjustments\Adjusters\CouponPercNum;
use Vanilo\Cart\Models\CartCoupons;
use Illuminate\Support\Collection;
use Vanilo\Cart\Models\Cart;

trait CheckoutFunctions
{
	public array $discounts = [];
	public array $applyableDiscounts = [];
	public array $conflictingDiscounts = [];

	public $validator;
	public $activeCoupon;

	public ShipmentMethod $shipping;
	public Country $selectedCountry;
	public Card $card;

	public function coupons()
	{
		if ($this instanceof Cart) {
			return $this->belongsToMany(Coupon::class, 'cart_coupons')->using(CartCoupons::class);
		} else if ($this instanceof Order) {
			return $this->belongsToMany(Coupon::class, 'orders_coupons')->using(OrderCoupon::class);
		}

		throw new Exception(
			sprintf(
				'Class must be an instance of %s or %s, %s given',
				Cart::class,
				Order::class,
				$this::class
			)
		);
	}

	public function getProductDiscounts()
	{
		$discounts = [];

		foreach ($this->items as $item) {
			$product = $item->product;
			$p_discounts = $product->validDiscountTree;

			if (isset($p_discounts)) {
				foreach ($p_discounts as $p_discount) {
					if (isset($discounts[$p_discount->id])) {
						array_push($discounts[$p_discount->id]['cart_items'], $item);
					} else {
						$discounts[$p_discount->id]['discount_data'] = $p_discount;
						$discounts[$p_discount->id]['cart_items'] = array();
						array_push($discounts[$p_discount->id]['cart_items'], $item);
					}
				}
			}
		}

		return $discounts;
	}

	public function getApplyableDiscounts()
	{
		$applyableDiscounts = [];

		foreach ($this->discounts as &$discount) {
			$discount_data = $discount['discount_data'];
			$cart_items = collect($discount['cart_items']);
			$discount['cart_items'] = $cart_items;

			$discount_tag = $discount_data->get_tipo_nome->tag;
			$discount['tag'] = $discount_tag;

			switch ($discount_tag) {
				case 'desconto_perc_euro':
					$applyableDiscounts[$discount_data->id] = $discount;
					break;
				case 'oferta_barato':
					if ($cart_items->sum('quantity') >= $discount_data->purchase_number && (!isset($discount_data->minimum_value) || (isset($discount_data->minimum_value) && $cart_items->sum('price_vat') >= $discount_data->minimum_value))) {
						# ordenar produtos por preco
						$discount['cart_items'] = $discount['cart_items']->sortBy('price_vat');

						$applyableDiscounts[$discount_data->id] = $discount;
					}
					break;
				case 'oferta_prod_igual':
					$applyableDiscounts[$discount_data->id] = $discount;
					break;
				case 'oferta_prod':
					if ($cart_items->sum('quantity') >= $discount_data->purchase_number) {
						$applyableDiscounts[$discount_data->id] = $discount;
					}
					break;
				case 'oferta_percentagem':
					# não está completamente implementado
					if ($cart_items->sum('quantity') >= $discount_data->num_min_buy) {
						$discount['cart_items'] = $discount['cart_items']->sortBy('price_vat');

						$applyableDiscounts[$discount_data->id] = $discount;
					}
					break;
				case 'oferta_desc_carrinho':
					break;
			}
		}

		return $applyableDiscounts;
	}

	public function getConflictingDiscounts()
	{
		$conflictingDiscounts = [];

		foreach ($this->applyableDiscounts as $discount) {
			$discount_data = $discount['discount_data'];
			foreach ($this->applyableDiscounts as $discout_comparisson) {
				$discount_comparisson_data = $discout_comparisson['discount_data'];
				if ($discount_data->id == $discount_comparisson_data->id) {
					continue;
				} else {
					foreach ($discount['cart_items'] as $item) {
						foreach ($discout_comparisson['cart_items'] as $item_comparisson) {
							if ($item->product->id == $item_comparisson->product->id) {
								$conflictingDiscounts[$discount_data->id] = $discount_data;
							}
						}
					}
				}
			}
		}

		return $conflictingDiscounts;
	}

	public function updateAdjustments()
	{
		debug('STARTING ADJUSTMENT UPDATES');
		$this->removeCouponAdjustments();
		$this->removeAllAdjustments();

		foreach ($this->items as $item) {
			$item->removeAllAdjustments();
			$item->updateIntervalAdjustments($this);
			$item->updateDirectDiscountAdjustments($this);
		}

		foreach ($this->applyableDiscounts as $discount) {
			$discount_data = $discount['discount_data'];

			foreach ($discount['cart_items'] as $item) {
				if ($discount['tag'] == 'desconto_perc_euro') {
					$item->adjustments()->create(new DiscountPercNum($this, $item, $discount_data));
				} else if ($discount['tag'] == 'oferta_barato') {
					$adjustment = $item->adjustments()->create(new DiscountLeastExpensiveFree($this, $item, $discount_data));

					if ($adjustment->getData('remainder_quantity')) {
						if ($adjustment->getData('remainder_quantity') == 0) {
							break;
						} else {
							$discount_data['remainder_quantity'] = $adjustment->getData('remainder_quantity');
						}
					} else {
						break; # break pq este desconto só vai ser aplicado 1x
					}
				} else if ($discount['tag'] == 'oferta_prod_igual') {
					$item->adjustments()->create(new DiscountSameFree($this, $item, $discount_data));
				} else if ($discount['tag'] == 'oferta_prod') {
					$item->adjustments()->create(new DiscountFree($this, $discount_data));
					break; # break pq este desconto só vai ser aplicado 1x
				} else if ($discount['tag'] == 'oferta_percentagem') {
					$item->adjustments()->create(new DiscountScalablePercNum($this, $discount_data));
					break; # break pq este desconto só vai ser aplicado 1x
				}
			}
		}

		foreach ($this->items as $item) {
			$item->updateStoreDiscountAdjustments($this);
		}

		if ($this->coupons->first()) {
			$this->validateCoupon($this->coupons->first());

			if ($this->validator->fails()) {
				$this->activeCoupon = null;
			} else {
				$this->activeCoupon = $this->coupons->first();
				$this->updateCouponAdjustments($this->coupons->first());
			}
		}

		$this->updateShippingFee();
		$this->updateClientCard();

		if ($this instanceof Cart) {
			$this->resetState();
		}

		debug('FINISHED ADJUSTMENT UPDATES');
	}

	public function itemsPreventFreeShipping($withItems = false)
	{
		$retval = $withItems ? [] : false;

		foreach ($this->items as $item) {
			if ($item->preventsFreeShipping()) {
				if ($withItems) {
					array_push($retval, $item);
				} else {
					$retval = true;
					break;
				}
			}
		}

		return $retval;
	}

	public function itemsTotal(): float
	{
		return $this->items->sum('total');
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
		$this->adjustments()->relation()->delete();
		/* $adjustments = $this->adjustments()->getIterator();

		foreach ($adjustments as $adjustment) {
			$this->removeAdjustment($adjustment);
		} */
	}

	public function setShipping(ShipmentMethod $shipping)
	{
		$this->shipping = $shipping;
	}

	public function setCard(Card $card)
	{
		$this->card = $card;
	}

	public function setCountry(Country $country)
	{
		$this->selectedCountry = $country;
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

	public function updateShippingFee()
	{
		if (!isset($this->shipping) || !isset($this->selectedCountry)) {
			return false;
		}


		$price = $this->shipping->price ?? 0;
		$threshold = null;

		if ($this->shipping->usesWeight()) {

			$shippingZone = $this->shipping->whereHasZonesAndCountry($this->selectedCountry)->first()->zones->first();

			if (!isset($shippingZone) || !isset($shippingZone->pivot)) {
				throw new Exception('Shipping method uses weights but no zone was found');
			}

			$shippingWeights = $this->shipping->weightIntervalThatFitsCart($this)->get();

			if (!isset($shippingWeights) && count($shippingWeights) == 0) {
				throw new Exception('Shipping method uses weights but no weight was found');
			}

			if ((!isset($shippingZone->pivot->max_weight) || $shippingZone->pivot->max_weight == 0 || $this->weight() < $shippingZone->pivot->max_weight) && !$this->itemsPreventFreeShipping() && $shippingZone->pivot->shipping_offer == 1) {
				$threshold = $shippingZone->pivot->min_value ?? null;
			}

			foreach ($shippingWeights as $shippingWeight) {
				if ($shippingWeight->zone_group_id == $shippingZone->id) {
					$price = $shippingWeight->price;
				}
			}
		}

		$this->removeAdjustment(null, AdjustmentTypeProxy::SHIPPING());
		$shippingAdjustment = $this->adjustments()->create(new SimpleShippingFee($price, $threshold));

		return $shippingAdjustment;
	}

	public function updateClientCard()
	{
		if (!isset($this->card)) {
			return false;
		}

		$balance = $this->card->balance() ?? 0;

		$this->removeAdjustment(null, AdjustmentTypeProxy::CLIENT_CARD());

		$clientCardAdjustment = $this->adjustments()->create(new ClientCard($balance, $this->card, $this));

		return $clientCardAdjustment;
	}

	public function getShippingAdjustment(): ?Adjustment
	{
		$shippingAdjustment = $this->getAdjustmentByType(AdjustmentTypeProxy::SHIPPING());
		$freeShippingAdjustmentCoupon = $this->getAdjustmentByType(AdjustmentTypeProxy::COUPON_FREE_SHIPPING());

		if (null === $freeShippingAdjustmentCoupon) {
			foreach ($this->items as $item) {
				if ($freeShippingAdjustmentCoupon = $item->getAdjustmentByType(AdjustmentTypeProxy::COUPON_FREE_SHIPPING())) {
					break;
				}
			}
		}

		if (null !== $shippingAdjustment) {
			$shippingAdjustment->display_amount = $shippingAdjustment->getAmount();
		}
		if (null !== $freeShippingAdjustmentCoupon) {
			$shippingAdjustment->display_amount += $freeShippingAdjustmentCoupon->getAmount();
		}

		return $shippingAdjustment;
	}

	public function getClientCardAdjustment(): ?Adjustment
	{
		$clientCardAdjustment = $this->getAdjustmentByType(AdjustmentTypeProxy::CLIENT_CARD());

		if (null !== $clientCardAdjustment) {
			$clientCardAdjustment->display_amount = $clientCardAdjustment->getAmount();
		}

		return $clientCardAdjustment;
	}

	public function removeCouponAdjustments()
	{
		foreach (AdjustmentTypeProxy::COUPONS()->value() as $value) {
			$const = Str::upper($value);
			$this->removeAdjustment(null, AdjustmentTypeProxy::$const());

			foreach ($this->items as $item) {
				$item->removeAdjustment(null, AdjustmentTypeProxy::$const());
			}
		}
	}

	public function getAdjustmentsTotals(): array
	{
		$adjTypes = AdjustmentTypeProxy::choices();

		foreach ($adjTypes as $key => $value) {
			$const = Str::upper($key);
			$label = $value;

			$adjTypes[$key] = [
				'label' => $label,
				'total' => $this->adjustments()->byType(AdjustmentTypeProxy::$const())->total()
			];
		}

		foreach ($this->items as $item) {
			foreach ($adjTypes as $key => &$value) {
				$const = Str::upper($key);

				$value['total'] += $item->adjustments()->byType(AdjustmentTypeProxy::$const())->total();
				$value['total'] = Utilities::FormatPrice($value['total'], 2, ',', '.');
			}
		}

		return $adjTypes;
	}

	public function hasDirectDiscounts()
	{
		foreach ($this->items as $item) {
			if ($item->product->validDirectDiscount()) {
				return true;
			}
		}

		return false;
	}

	public function hasDiscountsForProducts(Collection $ids)
	{
		$bool = false;

		foreach ($this->applyableDiscounts as $discount) {
			foreach ($discount['cart_items'] as $item) {
				if (count($ids->where('product_id', $item->product->id)) > 0) {
					$bool = true;
					break;
				}
			}
		}

		return $bool;
	}

	public function hasDirectDiscountsForProducts(Collection $ids)
	{
		$bool = false;

		foreach ($this->items as $item) {
			if (count($ids->where('product_id', $item->product->id)) > 0 && $item->product->validDirectDiscount()) {
				$bool = true;
				break;
			}
		}

		return $bool;
	}

	/**
	 * Creates a cart coupon, which is applyed after the next cart update
	 * So assuming this is a stepped checkout after the applyCoupon is called the page will reload and the cart should update
	 *
	 * @param Coupon $coupon
	 *
	 * @return CartCoupon
	 */
	public function applyCoupon(Coupon $coupon)
	{
		$this->coupons()->attach($coupon);
	}

	public function updateCouponAdjustments(Coupon $coupon)
	{
		if ($coupon->isSpecificToProducts()) {
			$validProducts = $coupon->fetchValidProductIds($this->items->pluck('product_id'));
		}

		foreach ($this->items as $item) {
			if (!isset($validProducts) || (isset($validProducts) && $validProducts->contains('product_id', $item->product_id))) {
				if ($coupon->type == CouponType::PERCENTAGE()->value() || $coupon->type == CouponType::NUMERARY()->value()) {
					$item->adjustments()->create(new CouponPercNum($this, $item, $coupon));
				} else if ($coupon->type == CouponType::FREESHIPPING()->value()) {
					$this->adjustments()->create(new CouponFreeShipping($this, $coupon));
					break;
				}
			}
		}
	}

	public function removeCoupon()
	{
		$this->removeCouponAdjustments();
		if (count($this->coupons) > 0) {
			$this->coupons()->sync([]);
		}
	}

	public function validateCoupon(Coupon $coupon)
	{
		$user = Auth::guard('web')->check() ? Auth::guard('web')->user() : false;

		$rules = [
			'bail',
			new IsCouponActive($coupon, $this),
			new IsStartDateValid($coupon, $this),
			new IsCouponExpired($coupon, $this),
			new HasUsesLeft($coupon, $this, $user),
			new IsUserAllowed($coupon, $this, $user),
			new OrderHasMinValue($coupon, $this),
			new CanBeUsedWithDiscounts($coupon, $this),
			new CanBeUsedWithProducts($coupon, $this)
		];

		if ($coupon->type == CouponType::FREESHIPPING()->value()) {
			$shippingAdjustment = $this->getAdjustmentByType(AdjustmentTypeProxy::SHIPPING());
			if (null !== $shippingAdjustment) {
				array_push($rules, new IsValidShippingAdjustment($coupon, $this, $shippingAdjustment));
			}

			if (isset($this->shipping) && isset($this->selectedCountry)) {
				array_push($rules, new CanBeUsedInZone($coupon, $this));
			}

			array_push($rules, new ProductsAllowFreeShipping($this));
		}

		$this->validator = Validator::make(['coupon_code' => $coupon->code], [
			'coupon_code' => $rules
		], [], [
			'coupon_code' => strtolower(__('frontoffice.coupon_checkout'))
		]);

		return $this->validator;
	}

	public function validator()
	{
		return isset($this->validator) ? $this->validator : null;
	}

	public function getActiveCoupon(): ?Coupon
	{
		return isset($this->activeCoupon) ? $this->activeCoupon : null;
	}

	public function shipping(): float
	{
		if ($this instanceof Order) {
			if ($this->isEditable()) {
				return $this->shippingValue();
			} else {
				return (float) $this->shipping_price;
			}
		} else if ($this instanceof Cart) {
			return $this->shippingValue();
		}
	}

	protected function shippingValue(): float
	{
		$shippingAdjustment = $this->getShippingAdjustment();
		return isset($shippingAdjustment) ? $shippingAdjustment->getAmount() : 0;
	}

	public function total(): float
	{
		if ($this instanceof Order) {
			if ($this->isEditable()) {
				return $this->totalValue();
			} else {
				return (float) $this->total;
			}
		} else if ($this instanceof Cart) {
			return $this->totalValue();
		}
	}

	protected function totalValue(): float
	{
		$total = $this->itemsTotal() + $this->adjustments()->total();

		$clientCardAdjustment = $this->adjustments()->byType(AdjustmentTypeProxy::CLIENT_CARD())->first();

		if (isset($clientCardAdjustment)) {
			$total += abs(floatval($clientCardAdjustment->amount));
		}

		return $total;
	}

	public function subTotal()
	{
		return $this->total() - $this->shipping();
	}

	/**
	 * @inheritDoc
	 */
	public function itemCount()
	{
		return $this->items->sum('quantity');
	}
}
