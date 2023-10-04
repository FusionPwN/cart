<?php

declare(strict_types=1);

namespace Vanilo\Cart\Models;

use App\Models\Admin\Prescription;
use Vanilo\Cart\Contracts\Cart as CartContract;
use Vanilo\Contracts\Buyable;
use App\Models\Admin\Product;
use App\Rules\CartValidForCheckout;
use Carbon\Carbon;
use Vanilo\Cart\Models\CartItemProxy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Konekt\Enum\Eloquent\CastsEnums;
use Vanilo\Cart\Exceptions\InvalidCartConfigurationException;
use Vanilo\Adjustments\Contracts\Adjustable;
use Vanilo\Adjustments\Models\AdjustmentTypeProxy;
use Vanilo\Adjustments\Support\HasAdjustmentsViaRelation;
use Vanilo\Adjustments\Support\RecalculatesAdjustments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Vanilo\Cart\Traits\CheckoutFunctions;
use App\Classes\Utilities;
use Vanilo\Product\Models\ProductStateProxy;

class Cart extends Model implements CartContract, Adjustable
{
	use CastsEnums;
	use HasAdjustmentsViaRelation;
	use RecalculatesAdjustments;
	use CheckoutFunctions;

	public $validator;

	public const EXTRA_PRODUCT_MERGE_ATTRIBUTES_CONFIG_KEY = 'vanilo.cart.extra_product_attributes';

	protected $guarded = ['id'];

	protected $enums = [
		'state' => 'CartStateProxy@enumClass'
	];

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
		if ($this->state->isLoading()) {
			return;
		}

		$this->setLoadingState();
		$this->buildCartGlobals();
		$this->updateAdjustments();
	}

	public function setLoadingState()
	{
		$this->state = CartStateProxy::LOADING();
		$this->save();
	}

	public function resetState()
	{
		$this->state = CartStateProxy::ACTIVE();
		$this->save();
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function items()
	{
		$model = $this->hasMany(CartItemProxy::modelClass(), 'cart_id', 'id')->where('product_type', 'product')->listableInverse();

		return $model;
	}

	public function prescriptionItem()
	{
		$model = $this->hasMany(CartItemProxy::modelClass(), 'cart_id', 'id')->where('product_type', 'prescription');

		return $model;
	}

	/**
	 * @inheritDoc
	 */
	public function getItems(): Collection
	{
		if ($this->hasPrescription()) {
			return $this->items->merge($this->prescriptionItem);
		} else {
			return $this->items;
		}
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

		$out = $this->outOfStockItems();

		foreach ($items['cart'] as &$item) {
			$item->display_quantity = $item->quantity;

			if ($out->where('product_id', $item->product_id)->count() > 0) {
				$item->out_of_stock = true;
			} else {
				$item->out_of_stock = false;
			}

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
					if ($adjustment->type != AdjustmentTypeProxy::OFERTA_PROD_IGUAL() &&  $adjustment->type != AdjustmentTypeProxy::OFERTA_PROD()) {
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

	public function checkAvailability(Buyable $product, $item, $qty)
	{
		# 1 se o produto tem stock - adiciona
		# 1.1 se tiver stock suficiente para adicionar adiciona tudo
		# 1.2 senao adiciona apenas o que tiver e envia um aviso a dizer quantas unidades adicionou

		# 2 nao tem stock
		# 2.1 mas tem disponibilidade limitada adiciona ao carrinho até ao valor maximo permitido desse produto
		# 2.2 tem stock ilimitado adiciona ao carrinho até ao valor maximo permitido desse produto
		# 2.3 nao adiciona nao tem stock

		$errors = [];

		if (!$product->isUnlimitedAvailability() && !$product->isLimitedAvailability()) {
			if ((null !== $item && ($item->quantity + $qty > $product->getStock())) || ($qty > $product->getStock())) {
				$qty = $product->getStock();
				$errors[] = (object) ['type' => 'warning', 'message' => 'not-enough-stock'];
			}
		}
		if (isset($product->max_stock_cart) && $product->max_stock_cart > 0) {
			if (null !== $item && $item->quantity + $qty > $product->max_stock_cart) {
				$qty = abs($product->max_stock_cart - $item->quantity);
				$errors[] = (object) ['type' => 'warning', 'message' => 'max-quantity-reached'];
			} else if (null === $item && $qty > $product->max_stock_cart) {
				$qty = $product->max_stock_cart;
				$errors[] = (object) ['type' => 'warning', 'message' => 'max-quantity-reached'];
			}
		}

		return (object) [
			'errors'	=> collect($errors),
			'quantity' 	=> $qty
		];
	}

	/**
	 * @inheritDoc
	 */
	public function addItem(Buyable $product, $qty = 1, $params = [])
	{
		if ($product->isSimpleProduct()) {
			$item = $this->items()->ofCart($this)->byProduct($product)->first();
			$qt = $qty;

			$result = $this->checkAvailability($product, $item, $qt);
			$qt = $result->quantity;

			if ($item) {
				if (count($result->errors) > 0) {
					$item->quantity = $qt;
				} else {
					$item->quantity += $qt;
				}

				if (isset($product->max_stock_cart) && $product->max_stock_cart > 0) {
					$item->quantity = $product->max_stock_cart;
				}

				$item->save();
			} else {
				$item = $this->items()->create(
					array_merge(
						$this->getDefaultCartItemAttributes($product, $qt),
						$this->getExtraProductMergeAttributes($product),
						$params['attributes'] ?? []
					)
				);
			}

			$this->load('items');
			$this->refresh();
			$this->cartInit();

			return (object) [
				'errors'	=> $result->errors,
				'item' 		=> $item
			];
		}
	}

	public function addPrescription(Prescription $prescription)
	{
		$item = $this->prescriptionItem()->ofCart($this)->first();

		if ($item) {
			if ($item->product_id != $prescription->id) {
				Prescription::find($item->product_id)->delete();
			}

			$item->product_id = $prescription->id;
			$item->save();
		} else {
			$item = $this->items()->create([
				'product_type' => 'prescription',
				'product_id' => $prescription->id,
				'quantity' => 1,
				'price_vat' => 0
			]);
		}

		return $item;
	}

	public function hasPrescription(): bool
	{
		return $this->prescriptionItem->count() > 0 ? true : false;
	}

	public function hasMNSRM(): bool
	{
		foreach ($this->items as $item) {
			if ($item->isMNSRM()) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function setItemQty($item, $qty = 1)
	{
		$qt = $qty;
		$result = $this->checkAvailability($item->product, $item, $qt);
		$qt = $result->quantity;

		if ($item) {
			if ($qt > 0) {
				$item->quantity = $qt;

				if (isset($item->product->max_stock_cart) && $item->product->max_stock_cart > 0) {
					$item->quantity = $item->product->max_stock_cart;
				}

				$item->save();
			} else {
				$this->removeItem($item);
			}
		}

		$this->load('items');
		$this->refresh();
		$this->cartInit();

		return (object) [
			'errors'	=> $result->errors,
			'item' 		=> $item
		];
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
		foreach ($this->items as $item) {
			$item->delete();
		}

		# funçao original (nao elimina os adjustments dos items)
		#$this->items()->ofCart($this)->delete();

		$this->load('items');
	}

	public function buildCartGlobals()
	{
		$this->discounts = $this->getProductDiscounts();
		$this->applyableDiscounts = $this->getApplyableDiscounts();
		$this->conflictingDiscounts = $this->getConflictingDiscounts();
	}

	public function totalAccumulatedCard(): float
	{
		$acumulatedValue = 0;
		
		if(Cache::get('settings.client_card') == 1) {
			if($this->getItems() !== null && count($this->getItems()) > 0)
			{
				foreach ($this->getItems() as $item) {
					if(($item->product->msrm == 1 || $item->product->mnsrm == 1 || $item->product->msrmv == 1 || $item->product->mnsrmv == 1) && Cache::get('settings.pecentage_credited_to_the_card_msrm') !== null && Cache::get('settings.pecentage_credited_to_the_card_msrm') !== '') {
						if(Cache::get('settings.max_pvp_msrm_to_the_card') !== null && Cache::get('settings.max_pvp_msrm_to_the_card') !== ''){
							if($item->price_vat <= (float) Cache::get('settings.max_pvp_msrm_to_the_card')){
								$acumulatedValue += Utilities::RoundPrice((Cache::get('settings.pecentage_credited_to_the_card_msrm') / 100) * $item->price_vat);
							}
						} else {
							$acumulatedValue += Utilities::RoundPrice((Cache::get('settings.pecentage_credited_to_the_card_msrm') / 100) * $item->price_vat);
						}
					} else {
						$acumulatedValue += Utilities::RoundPrice((Cache::get('settings.pecentage_credited_to_the_card') / 100) * $item->price_vat);
					}
				}
			}
		}

		return $acumulatedValue;
	}

	public function totalWithCard(): float
	{
		return $this->itemsTotal() + $this->adjustments()->total();
	}

	public function weight(): float
	{
		return (float) $this->items->sum(function ($item) {
			return (float) $item->weight();
		});
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

	public function scopeAbandonded(Builder $query, ?int $since = 5, ?string $type = 'MINUTE', ?bool $grouped = false, ?string $orderby = 'ASC')
	{
		$query->select(DB::raw("DATE_ADD(NOW(), INTERVAL -$since $type) AS since"), DB::raw('COUNT(*) AS count'))
			->where('state', CartStateProxy::ABANDONDED()->value())
			->whereBetween('created_at', [DB::raw("DATE_ADD(NOW(), INTERVAL -$since $type)"), DB::raw('NOW()')]);

		if ($grouped) {
			$query->orderBy('created_at', $orderby)
				->groupBy(DB::raw('DATE(created_at)'));
		}

		return $query;
	}

	public function scopeAbandondedWithinDates(Builder $query, $start, ?string $end = null, ?bool $grouped = false, ?string $orderby = 'ASC')
	{
		if (!isset($end)) {
			$end = Carbon::now()->addDay();
		} else {
			$end = Carbon::parse($end)->addDay();
		}

		$query->select(DB::raw("DATE(created_at) AS since"), DB::raw('COUNT(*) AS count'))
			->where('state', CartStateProxy::ABANDONDED()->value())
			->whereBetween('created_at', [DB::raw("DATE('$start')"), DB::raw("'$end'")]);

		if ($grouped) {
			$query->orderBy('created_at', $orderby)
				->groupBy(DB::raw('DATE(created_at)'));
		}

		return $query;
	}

	public function scopeAbandondedToday(Builder $query, ?string $orderby = 'ASC')
	{
		return $query->select(DB::raw('DATE(created_at) AS created_at'), DB::raw('COUNT(*) AS count'))
			->where('state', CartStateProxy::ABANDONDED()->value())
			->where(DB::raw('WEEK(DATE(created_at))'), DB::raw('WEEK(NOW())'))
			->where(DB::raw('DAY(DATE(created_at))'), DB::raw('DAY(NOW())'))
			->orderBy(DB::raw('DATE(created_at)'), $orderby)
			->groupBy(DB::raw('DATE(created_at)'));
	}

	public function scopeAbandondedThisWeek(Builder $query, ?string $orderby = 'ASC')
	{
		return $query->select(DB::raw('DATE(created_at) AS created_at'), DB::raw('COUNT(*) AS count'))
			->where('state', CartStateProxy::ABANDONDED()->value())
			->where(DB::raw('WEEK(DATE(created_at))'), DB::raw('WEEK(NOW())'))
			->orderBy(DB::raw('DATE(created_at)'), $orderby)
			->groupBy(DB::raw('DATE(created_at)'));
	}

	public function scopeAbandondedThisMonth(Builder $query, ?string $orderby = 'ASC')
	{
		return $query->select(DB::raw('DATE(created_at) AS created_at'), DB::raw('COUNT(*) AS count'))
			->where('state', CartStateProxy::ABANDONDED()->value())
			->where(DB::raw('MONTH(DATE(created_at))'), DB::raw('MONTH(NOW())'))
			->where(DB::raw('YEAR(DATE(created_at))'), DB::raw('YEAR(NOW())'))
			->orderBy(DB::raw('DATE(created_at)'), $orderby)
			->groupBy(DB::raw('DATE(created_at)'));
	}

	public function scopeAbandondedThisYear(Builder $query, ?string $orderby = 'ASC')
	{
		return $query->select(DB::raw('DATE(created_at) AS created_at'), DB::raw('COUNT(*) AS count'))
			->where('state', CartStateProxy::ABANDONDED()->value())
			->where(DB::raw('YEAR(DATE(created_at))'), DB::raw('YEAR(NOW())'))
			->orderBy(DB::raw('DATE(created_at)'), $orderby)
			->groupBy(DB::raw('DATE(created_at)'));
	}

	public function outOfStockItems()
	{
		$out = collect();

		foreach ($this->items as $item) {
			if (!$item->product->isOnStock()) {
				$out->add($item);
			}
		}

		return $out;
	}

	public function validator()
	{
		return isset($this->validator) ? $this->validator : null;
	}

	public function isValid()
	{
		$this->validator = Validator::make(['cart' => 1], [
			'cart' => [new CartValidForCheckout($this)]
		], [], []);

		return !$this->validator->fails();
	}
}
