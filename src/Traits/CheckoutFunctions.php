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
use Vanilo\Adjustments\Adjusters\CouponPerc;
use Vanilo\Adjustments\Adjusters\CouponNum;
use Vanilo\Cart\Models\CartCoupons;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Vanilo\Adjustments\Adjusters\FeePackagingBag;
use Vanilo\Cart\Models\Cart;
use App\Models\Admin\PostalCodeWhitelist;

trait CheckoutFunctions
{
	public array $discounts = [];
	public array $applyableDiscounts = [];
	public array $conflictingDiscounts = [];

	public $couponValidator;
	public $activeCoupon;
	public $shippingAddress;

	public ShipmentMethod $shipping;
	public Country $selectedCountry;
	public Card $card;

	public function coupons()
	{
		if ($this instanceof Cart) {
			return $this->belongsToMany(Coupon::class, 'cart_coupons')->using(CartCoupons::class);
		} else if ($this instanceof Order) {
			return $this->belongsToMany(Coupon::class, 'orders_coupons');
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


	/**
	 * Returns a specific item from cart
	 * 
	 * @param $id (product id)
	 * 
	 */
	public function getItem($value, ?string $field = 'id')
	{
		return $this->items->where("product.$field", $value)->first();
	}

	/**
	 * Checks if item exists in cart
	 * 
	 * @param $id (product id)
	 * 
	 * @return bool
	 */
	public function hasItem($value, ?string $field = 'id'): bool
	{
		return (bool) count($this->items->where("product.$field", $value)) > 0;
	}

	/**
	 * Checks if items exists in cart
	 * 
	 * @param $ids (product id)
	 * 
	 * @return bool
	 */
	public function hasItems($ids, ?string $field = 'id'): bool
	{
		foreach ($ids as $id) {
			if ($this->hasItem($id, $field)) {
				return true;
			}
		}

		return false;
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

					break; # NAO PERMITIR QUE MAIS DO QUE UMA CAMPANHA SEJA APLICADA AO PRODUTO
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

			if ($discount_data->isApplyable($cart_items)) {
				switch ($discount_tag) {
					case 'desconto_perc_euro':
						break;
					case 'oferta_barato':
						$discount['cart_items'] = $discount['cart_items']->sortBy('prices.price_unit');
						break;
					case 'oferta_prod_igual':
						break;
					case 'oferta_prod':
						break;
					case 'oferta_percentagem':
						$discount['cart_items'] = $discount['cart_items']->sortByDesc('prices.price_unit')->values(); # values()->all() para fazer reset nas keys do array
						break;
					case 'oferta_desc_carrinho':
						break;
				}

				$applyableDiscounts[$discount_data->id] = $discount;
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

			if ($this instanceof Order && $item->overridesPrice()) {
				#keep empty
			} else {
				$item->updateIntervalAdjustments($this);
				$item->updateDirectDiscountAdjustments($this);
			}
		}

		foreach ($this->applyableDiscounts as $discount) {
			$discount_data = $discount['discount_data'];
			$item_count = $discount['cart_items']->sum('quantity');

			if ($discount['tag'] == 'oferta_percentagem') {
				$level_list_count = 0;
				$level_count = 0;
				$level_list = [];

				if ($discount_data->properties->highest == 1) {
					$max_level = $item_count > count($discount_data->properties->levels) ? count($discount_data->properties->levels) - 1 : $item_count - 1;

					for ($i = 0; $i < $item_count; $i++) {
						$level_list[] = [
							'level' => $max_level,
							'value' => $discount_data->properties->levels[$max_level]
						];
					}
				} else {
					for ($i = 0; $i < $item_count; $i++) {
						$level_count = $level_count > count($discount_data->properties->levels) - 1 ? 0 : $level_count;
						$level_list[] = [
							'level' => $level_count,
							'value' => $discount_data->properties->levels[$level_count] # este valor apenas serve para a ordenaçao
						];

						$level_count++;
					}
				}

				$level_list = collect($level_list)->sortBy('value')->values();
			}


			foreach ($discount['cart_items'] as $item) {
				if ($this instanceof Order && $item->overridesPrice()) {
				} else {
					if ($item->product->validDirectDiscount() && !$item->product->directDiscountStacksWithDiscounts()) {
						continue;
					}

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
						for ($i = 0; $i < $item->quantity; $i++) {
							$this->adjustments()->create(new DiscountScalablePercNum($this, $item, $discount_data, $level_list[$level_list_count]['level']));
							$level_list_count++;
						}
					}
				}
			}
		}

		foreach ($this->items as $item) {
			if ($this instanceof Order && $item->overridesPrice()) {
			} else {
				$item->updateStoreDiscountAdjustments($this);
			}
		}

		$this->updateShippingFee();
		if (null !== $this->id) {
			$this->updateFeePackagingBag();
		}

		if ($this->coupons->first()) {
			$this->validateCoupon($this->coupons->first());

			if ($this->couponValidator->fails()) {
				$this->activeCoupon = null;
			} else {
				$this->activeCoupon = $this->coupons->first();
				$this->updateCouponAdjustments($this->coupons->first());
			}
		}

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

	public function vatTotal(): float
	{
		return $this->itemsVatTotal();
	}

	public function itemsVatTotal(): float
	{
		return $this->items->sum(function ($item) {
			return $item->vatTotal();
		});
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

	public function removeAllAdjustments($removeFromItems = false)
	{
		$this->adjustments()->relation()->delete();

		if ($removeFromItems) {
			foreach ($this->items as $item) {
				$item->removeAllAdjustments();
			}
		}
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

	public function setShippingAddress($shippingAddress)
	{
		$this->shippingAddress = $shippingAddress;
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
		}else if($this->shipping->isHomeDelivery()){
			$havePriceInPostalCode = PostalCodeWhitelist::where('postalcode',$this->shippingAddress['postalcode'])->get();

			if(count($havePriceInPostalCode) > 0) {
				if (!$this->itemsPreventFreeShipping() && $havePriceInPostalCode->first()->shipping_offer == 1) {
					$threshold = $havePriceInPostalCode->first()->value ?? null;
				}
				
				$price = $havePriceInPostalCode->first()->shipping_price;
			} else {
				$postalCodeArr = explode('-',$this->shippingAddress['postalcode']);

				$havePriceInPostalCode = PostalCodeWhitelist::where('postalcode',$postalCodeArr[0])->get();

				if(count($havePriceInPostalCode) == 0) {
					//Não existe este código postal na tabela logo elimina do array de metodos de envio
					throw new Exception('Este código postal não é valido para entrega ao domicilio');
				} else if(count($havePriceInPostalCode) == 1){
					if (!$this->itemsPreventFreeShipping() && $havePriceInPostalCode->first()->shipping_offer == 1) {
						$threshold = $havePriceInPostalCode->first()->value ?? null;
					}
					//Encontrou um resultado coloca o preço que está definido
					$price = $havePriceInPostalCode->first()->shipping_price;
				} else if(count($havePriceInPostalCode) > 1) {
					//Encontrou mais que 1 resultado primeiro verificar se os preços são iguais
					$priceT = $havePriceInPostalCode->first()->shipping_price;
					$count = 0;
					foreach($havePriceInPostalCode as $item){
						if($item->shipping_price != $priceT){
							$count = 1;
						}
					}
					
					if($count == 0){
						if (!$this->itemsPreventFreeShipping() && $havePriceInPostalCode->first()->shipping_offer == 1) {
							$threshold = $havePriceInPostalCode->first()->value ?? null;
						}
						$price = $priceT;
					} else {
						// Get cURL resource
						$curl = curl_init();
						// Set some options - we are passing in a useragent too here
						curl_setopt_array($curl, array(
							CURLOPT_RETURNTRANSFER => 1,
							CURLOPT_URL => 'http://codpostal.coolsis.pt/?codpostal1='.$postalCodeArr[0].'&codpostal2='.$postalCodeArr[1],
						));
						// Send the request & save response to $resp
						$resp = curl_exec($curl);
						if ($resp) {
							$json_array = (array) json_decode($resp);
							$ret['localidade'] = $json_array[0]->localidade;
							$ret['nome_concelho'] =  $json_array[0]->nome_concelho;
							$ret['nome_distrito'] =  $json_array[0]->nome_distrito;
							$havePriceInPostalCode = PostalCodeWhitelist::where('postalcode',$postalCodeArr[0])->whereRaw('LOWER(parish) = ?', [strtolower($json_array[0]->localidade)])->first();
							if(isset($havePriceInPostalCode)){
								if (!$this->itemsPreventFreeShipping() && $havePriceInPostalCode->shipping_offer == 1) {
									$threshold = $havePriceInPostalCode->value ?? null;
								}
								$price= $havePriceInPostalCode->shipping_price;
							}
						} else {
							throw new Exception('Este código postal não é valido para entrega ao domicilio');
						}
						//Close request to clear up some resources
						curl_close($curl);
					}
				}
			}
		}

		$this->removeAdjustment(null, AdjustmentTypeProxy::SHIPPING());
		$shippingAdjustment = $this->adjustments()->create(new SimpleShippingFee($this->shipping, $price, $threshold));

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

	public function updateFeePackagingBag()
	{
		if (Cache::get('settings.checkout_packaging_of_the_order') !== null && Cache::get('settings.checkout_packaging_of_the_order') != "") {
			$this->removeAdjustment(null, AdjustmentTypeProxy::FEE_PACKAGING_BAG());
			$feePackagingBagAdjustment = $this->adjustments()->create(new FeePackagingBag(Cache::get('settings.checkout_packaging_of_the_order')));

			return $feePackagingBagAdjustment;
		}

		return false;
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

			if (null !== $freeShippingAdjustmentCoupon) {
				$shippingAdjustment->display_amount += $freeShippingAdjustmentCoupon->getAmount();
			}
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

	public function getFeePackagingBagAdjustment(): ?Adjustment
	{
		$feePackagingBagAdjustment = $this->getAdjustmentByType(AdjustmentTypeProxy::FEE_PACKAGING_BAG());

		if (null !== $feePackagingBagAdjustment) {
			$feePackagingBagAdjustment->display_amount = $feePackagingBagAdjustment->getAmount();
		}

		return $feePackagingBagAdjustment;
	}

	public function removeCouponAdjustments()
	{
		foreach (AdjustmentTypeProxy::CouponChoices(keys_only: true) as $value) {
			$const = Str::upper($value);
			$this->removeAdjustment(null, AdjustmentTypeProxy::$const());

			foreach ($this->items as $item) {
				$item->removeAdjustment(null, AdjustmentTypeProxy::$const());
			}
		}
	}


	public function getAdjustmentsTotalsParcels(): object
	{
		$adjTypes = AdjustmentTypeProxy::choices();
		$returnType = $adjTypes;

		foreach ($adjTypes as $key => $value) {
			$const = Str::upper($key);
			$label = $value;

			$returnType[$key] = (object) [
				'label' => $label,
				'total' => $this->adjustments()->byType(AdjustmentTypeProxy::$const())->total()
			];
		}

		foreach ($this->items as $item) {
			foreach ($adjTypes as $key => &$value) {
				$const = Str::upper($key);
				$label = $value;
				$returnType[$key] = (object) [
					'label' => $label,
					'total' => $returnType[$key]->total + ($item->adjustments()->byType(AdjustmentTypeProxy::$const())->total())
				];
			}
		}

		return (object) $returnType;
	}

	public function getAdjustmentsDiscountTotal(): float
	{
		$total = 0;
		$parcels = $this->getAdjustmentsTotalsParcels();

		foreach ($parcels as $parcel) {
			if ($parcel->total < 0) {
				$total += ($parcel->total);
			}
		}

		return abs($total);
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
		if (null === $this->coupons()->where('coupon_id', $coupon->id)->first()) {
			$this->coupons()->attach($coupon);
		}
	}

	public function updateCouponAdjustments(Coupon $coupon)
	{
		if ($coupon->isSpecificToProducts()) {
			$validProducts = $coupon->fetchValidProductIds($this->items->pluck('product_id'));
		}

		foreach ($this->items as $item) {
			if (!isset($validProducts) || (isset($validProducts) && $validProducts->contains('product_id', $item->product_id))) {
				if ($coupon->type == CouponType::PERCENTAGE()) {
					$item->adjustments()->create(new CouponPerc($this, $item, $coupon));
				} else if ($coupon->type == CouponType::NUMERARY()) {
					$item->adjustments()->create(new CouponNum($this, $item, $coupon));
				} else if ($coupon->type == CouponType::FREESHIPPING()) {
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

		if ($coupon->type == CouponType::FREESHIPPING()) {
			$shippingAdjustment = $this->getAdjustmentByType(AdjustmentTypeProxy::SHIPPING());
			if (null !== $shippingAdjustment) {
				array_push($rules, new IsValidShippingAdjustment($coupon, $this, $shippingAdjustment));
			}

			if (isset($this->shipping) && isset($this->selectedCountry)) {
				array_push($rules, new CanBeUsedInZone($coupon, $this));
			}

			array_push($rules, new ProductsAllowFreeShipping($this));
		}

		$this->couponValidator = Validator::make(['coupon_code' => $coupon->code], [
			'coupon_code' => $rules
		], [], [
			'coupon_code' => strtolower(__('frontoffice.coupon_checkout'))
		]);

		return $this->couponValidator;
	}

	public function couponValidator()
	{
		return isset($this->couponValidator) ? $this->couponValidator : null;
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

	public function feePackagingBag(): float
	{
		if ($this instanceof Order) {
			if ($this->isEditable()) {
				return $this->feePackagingBagValue();
			} else {
				return (float) isset($this->getFeePackagingBag->value) ? $this->getFeePackagingBag->value : 0;
			}
		} else if ($this instanceof Cart) {
			return $this->feePackagingBagValue();
		}
	}

	protected function shippingValue(): float
	{
		$shippingAdjustment = $this->getShippingAdjustment();
		return isset($shippingAdjustment) ? $shippingAdjustment->getAmount() : 0;
	}

	protected function feePackagingBagValue(): float
	{
		$feePackagingBagAdjustment = $this->getFeePackagingBagAdjustment();
		return isset($feePackagingBagAdjustment) ? $feePackagingBagAdjustment->getAmount() : 0;
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
		return $this->total() - $this->shipping() - $this->feePackagingBag();
	}

	/**
	 * @inheritDoc
	 */
	public function itemCount()
	{
		return $this->items->sum('quantity');
	}

	public function couponDiscount(): float
	{
		$value = 0;

		if ($this instanceof Cart) {
			foreach ($this->adjustments()->relation()->get() as $adjustment) {
				if (AdjustmentTypeProxy::IsCoupon($adjustment->type)) {
					$value = $adjustment->getAmount();
				}
			}
			foreach ($this->items as $item) {
				foreach ($item->adjustments()->relation()->get() as $adjustment) {
					if (AdjustmentTypeProxy::IsCoupon($adjustment->type)) {
						$value = $adjustment->getAmount();
					}
				}
			}
		} else if ($this instanceof Order) {
		}

		return abs($value);
	}
}
