<?php

declare(strict_types=1);

namespace Vanilo\Cart\Models;

use App\Classes\Utilities;
use Vanilo\Adjustments\Adjusters\CouponFreeShipping;
use Vanilo\Adjustments\Adjusters\CouponPercNum;
use Vanilo\Adjustments\Adjusters\DiscountFree;
use Vanilo\Adjustments\Adjusters\DiscountLeastExpensiveFree;
use Vanilo\Adjustments\Adjusters\DiscountPercNum;
use Vanilo\Adjustments\Adjusters\DiscountSameFree;
use Vanilo\Adjustments\Adjusters\DiscountScalablePercNum;
use Vanilo\Adjustments\Adjusters\SimpleShippingFee;
use App\Models\Admin\Coupon;
use Vanilo\Cart\Contracts\Cart as CartContract;
use Vanilo\Contracts\Buyable;
use App\Models\Admin\CouponType;
use App\Models\Admin\Product;
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
use Vanilo\Cart\Models\CartItemProxy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Konekt\Address\Models\Country;
use Konekt\Enum\Eloquent\CastsEnums;
use Vanilo\Cart\Exceptions\InvalidCartConfigurationException;
use Vanilo\Adjustments\Contracts\Adjustable;
use Vanilo\Adjustments\Models\Adjustment;
use Vanilo\Adjustments\Models\AdjustmentTypeProxy;
use Vanilo\Adjustments\Support\HasAdjustmentsViaRelation;
use Vanilo\Adjustments\Support\RecalculatesAdjustments;
use Illuminate\Support\Facades\Validator;
use Vanilo\Adjustments\Contracts\AdjustmentType;

class Cart extends Model implements CartContract, Adjustable
{
	use CastsEnums;
	use HasAdjustmentsViaRelation;
	use RecalculatesAdjustments;

	public const EXTRA_PRODUCT_MERGE_ATTRIBUTES_CONFIG_KEY = 'vanilo.cart.extra_product_attributes';

	protected $guarded = ['id'];

	protected $enums = [
		'state' => 'CartStateProxy@enumClass'
	];

	public array $discounts = [];
	public array $applyableDiscounts = [];
	public array $conflictingDiscounts = [];

	public $validator;
	public $activeCoupon;

	public ShipmentMethod $shipping;
	public Country $country;

	public static function boot()
	{
		parent::boot();

		static::retrieved(function ($model) {
			$model->cartInit();
		});

		static::deleting(function ($model) {
			$model->removeAllAdjustments();
			$model->clear();
		});
	}

	public function cartInit()
	{
		$this->buildCartGlobals();
		$this->updateAdjustments();
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function items()
	{
		$model = $this->hasMany(CartItemProxy::modelClass(), 'cart_id', 'id')->actives();

		if (Cache::get('settings.infinite-stock') == 0) {
			$model = $model->hasStock();
		}

		return $model;
	}

	/**
	 * @inheritDoc
	 */
	public function getItems(): Collection
	{
		return $this->items;
	}

	public function cartCoupon(): HasOne
	{
		return $this->hasOne(CartCoupons::class, 'cart_id', 'id');
	}

	/**
	 * Marosca para clonar items de oferta
	 */
	public function getItemsDisplay(): Collection
	{
		$items = [
			'cart' => clone $this->items,
			'free' => []
		];

		foreach ($items['cart'] as &$item) {
			$item->display_quantity = $item->quantity;
			foreach ($item->adjustments()->getIterator() as $adjustment) {
				if (AdjustmentTypeProxy::IsVisualSeparator($adjustment->type)) {
					if ($adjustment->type == AdjustmentTypeProxy::OFERTA_BARATO() || $adjustment->type == AdjustmentTypeProxy::OFERTA_PROD_IGUAL()) {
						$free_item = clone $item;
					} else if ($adjustment->type == AdjustmentTypeProxy::OFERTA_PROD()) {
						$free_item = clone $item;
						# Esta assim porque se formos buscar o produto direto nao temos model do cartitem e da erro a carregar o produto.
						$free_item->setRelation('product', Product::where('sku', $adjustment->getData('sku'))->get()->first());
					}

					$free_quantity = $adjustment->getData('quantity');
					if($adjustment->type != AdjustmentTypeProxy::OFERTA_PROD_IGUAL() &&  $adjustment->type != AdjustmentTypeProxy::OFERTA_PROD()){
						$item->display_quantity -= $free_quantity;
					}
					$free_item->display_quantity = $free_quantity;
					array_push($items['free'], $free_item);
				}
			}
		}

		$items['free'] = collect($items['free']);

		return collect($items);
	}

	/**
	 * @inheritDoc
	 */
	public function itemCount()
	{
		return $this->items->sum('quantity');
	}

	/**
	 * Returns a specific item from cart
	 * 
	 * @param $id (product id)
	 * 
	 * @return CartItem
	 */
	public function getItem($id): ?CartItem
	{
		return $this->items->where('product.id', $id)->first();
	}

	/**
	 * Checks if item exists in cart
	 * 
	 * @param $id (product id)
	 * 
	 * @return bool
	 */
	public function hasItem($id): bool
	{
		return (bool) count($this->items->where('product.id', $id)) > 0;
	}

	/**
	 * Checks if items exists in cart
	 * 
	 * @param $ids (product id)
	 * 
	 * @return bool
	 */
	public function hasItems($ids): bool
	{
		foreach ($ids as $id) {
			if ($this->hasItem($id)) { return true; }
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function addItem(Buyable $product, $qty = 1, $params = [])
	{
		if ($product->isSimpleProduct()) {
			$item = $this->items()->ofCart($this)->byProduct($product)->first();

			if (Cache::get('settings.infinite-stock') == 0 && ($qty > $product->getStock() || (null !== $item && $item->quantity + $qty > $product->getStock()))) {
				return (object) [
					'error' => 'not-enough-stock'
				];
			}

			if ($item) {
				$item->quantity += $qty;
				$item->save();
			} else {
				$item = $this->items()->create(
					array_merge(
						$this->getDefaultCartItemAttributes($product, $qty),
						$this->getExtraProductMergeAttributes($product),
						$params['attributes'] ?? []
					)
				);
			}

			$this->load('items');
			$this->refresh();
			$this->cartInit();

			return $item;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function setItemQty($item, $qty = 1)
	{
		if (Cache::get('settings.infinite-stock') == 0 && $qty > $item->product->getStock()) {
			return (object) [
				'error' => 'not-enough-stock'
			];
		}

		if ($item) {
			$item->quantity = $qty;
			$item->save();
		}

		$this->load('items');
		$this->refresh();
		$this->cartInit();

		return $item;
	}

	/**
	 * @inheritDoc
	 */
	public function removeItem($item)
	{
		if ($item) {
			$item->delete();
		}

		$this->load('items');
		$this->refresh();
		$this->cartInit();
	}

	/**
	 * @inheritDoc
	 */
	public function removeProduct(Buyable $product)
	{
		$item = $this->items()->ofCart($this)->byProduct($product)->first();

		$this->removeItem($item);
	}

	/**
	 * @inheritDoc
	 */
	public function clear()
	{
		# os items tem de ser removidos 1 a 1 caso contrario nao elimina os adjustments dos items
		foreach($this->items as $item) {
			$item->delete();
		}

		# funçao original (nao elimina os adjustments dos items)
		#$this->items()->ofCart($this)->delete();

		$this->load('items');
	}

	public function getProductDiscounts() {
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

	public function getApplyableDiscounts() {
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

	public function getConflictingDiscounts() {
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

	public function updateAdjustments() {
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

					if($adjustment->getData('remainder_quantity')){
						if($adjustment->getData('remainder_quantity') == 0){
							break;
						}else{
							$discount_data['remainder_quantity'] = $adjustment->getData('remainder_quantity');
						}
					}else{
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

		$this->updateShippingFee();

		if ($this->cartCoupon) {
			$this->validateCoupon($this->cartCoupon->coupon);

			if ($this->validator->fails()) {
				$this->activeCoupon = null;
			} else {
				$this->activeCoupon = $this->cartCoupon->coupon;
				$this->updateCouponAdjustments($this->cartCoupon->coupon);
			}
		}

		debug('FINISHED ADJUSTMENT UPDATES');
	}

	public function buildCartGlobals() {
		$this->discounts = $this->getProductDiscounts();
		$this->applyableDiscounts = $this->getApplyableDiscounts();
		$this->conflictingDiscounts = $this->getConflictingDiscounts();
	}

	public function total(): float
	{
		return $this->itemsTotal() + $this->adjustments()->total();
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
		return $this->items->sum(function($item) {
			return $item->vatTotal();
		});
	}

	/**
	 * @inheritDoc
	 */
	public function subTotal(): float
	{
		/* return (float) $this->items->sum(function ($item) {
			return (float) $item->product->price_vat * $item->quantity;
		}); */

		$subtotal = $this->total();
		$shippingAdjustment = $this->getShippingAdjustment();
		$subtotal -= isset($shippingAdjustment) ? $shippingAdjustment->getAmount() : 0;
		
		return $subtotal;
	}

	public function weight(): float
	{
		return (float) $this->items->sum(function($item) {
			return (float) $item->weight();
		});
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

	/**
	 * The cart's user relationship
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function user()
	{
		$userModel = config('vanilo.cart.user.model') ?: config('auth.providers.users.model');

		return $this->belongsTo($userModel);
	}

	/**
	 * @inheritDoc
	 */
	public function getUser()
	{
		return $this->user;
	}

	/**
	 * @inheritDoc
	 */
	public function setUser($user)
	{
		if ($user instanceof Authenticatable) {
			$user = $user->id;
		}

		$this->user_id = $user;
	}

	public function scopeActives($query)
	{
		return $query->whereIn('state', CartState::getActiveStates());
	}

	public function scopeOfUser($query, $user)
	{
		return $query->where('user_id', is_object($user) ? $user->id : $user);
	}

	/**
	 * Returns the default attributes of a Buyable for a cart item
	 *
	 * @param Buyable $product
	 * @param integer $qty
	 *
	 * @return array
	 */
	protected function getDefaultCartItemAttributes(Buyable $product, $qty)
	{
		return [
			'product_type' 	=> $product->morphTypeName(),
			'product_id' 	=> $product->getId(),
			'quantity' 		=> $qty,
			'price_vat' 	=> $product->getPriceVat()
		];
	}

	/**
	 * Returns the extra product merge attributes for cart_items based on the config
	 *
	 * @param Buyable $product
	 *
	 * @throws InvalidCartConfigurationException
	 *
	 * @return array
	 */
	protected function getExtraProductMergeAttributes(Buyable $product)
	{
		$result = [];
		$cfg = config(self::EXTRA_PRODUCT_MERGE_ATTRIBUTES_CONFIG_KEY, []);

		if (!is_array($cfg)) {
			throw new InvalidCartConfigurationException(
				sprintf(
					'The value of `%s` configuration must be an array',
					self::EXTRA_PRODUCT_MERGE_ATTRIBUTES_CONFIG_KEY
				)
			);
		}

		foreach ($cfg as $attribute) {
			if (!is_string($attribute)) {
				throw new InvalidCartConfigurationException(
					sprintf(
						'The configuration `%s` can only contain an array of strings, `%s` given',
						self::EXTRA_PRODUCT_MERGE_ATTRIBUTES_CONFIG_KEY,
						gettype($attribute)
					)
				);
			}

			$result[$attribute] = $product->{$attribute};
		}

		return $result;
	}

	public function removeAdjustment(Adjustment $adjustment = null, AdjustmentType $type = null) {
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

	public function setCountry(Country $country)
	{
		$this->country = $country;
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
		if (!isset($this->shipping) || !isset($this->country)) { return false; }

		$price = $this->shipping->price ?? 0;
		$threshold = null;

		if ($this->shipping->usesWeight()) {
			$shippingZone = $this->shipping->whereHasZonesAndCountry($this->country)->first()->zones->first();

			if (!isset($shippingZone) || !isset($shippingZone->pivot)) {
				throw new Exception('Shipping method uses weights but no zone was found');
			}

			$shippingWeight = $this->shipping->weightIntervalThatFitsCart($this)->first();

			if (!isset($shippingWeight)) {
				throw new Exception('Shipping method uses weights but no weight was found');
			}

			if ((!isset($shippingZone->pivot->max_weight) || $shippingZone->pivot->max_weight == 0 || $this->weight() < $shippingZone->pivot->max_weight) && !$this->itemsPreventFreeShipping()) {
				$threshold = $shippingZone->pivot->min_value ?? null;
			}

			$price = $shippingWeight->price;
		}

		$this->removeAdjustment(null, AdjustmentTypeProxy::SHIPPING());
		$shippingAdjustment = $this->adjustments()->create(new SimpleShippingFee($price, $threshold));

		return $shippingAdjustment;
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

	public function getAdjustmentsTotals(): Array
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
				$value['total'] = Utilities::FormatPrice($value['total'],2,',','.');
			}
		}

		return $adjTypes;
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
		return CartCoupons::create([
			'cart_id' 	=> $this->id,
			'coupon_id' => $coupon->id,
		]);
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
		if (isset($this->cartCoupon)) {
			$this->cartCoupon->delete();
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
			new HasUsesLeft($coupon, $this),
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

			if (isset($this->shipping) && isset($this->country)) {
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
}
