<?php

declare(strict_types=1);

namespace Vanilo\Cart\Models;

use Vanilo\Cart\Contracts\Cart as CartContract;
use Vanilo\Contracts\Buyable;
use App\Models\Admin\Product;
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
use Vanilo\Cart\Traits\CheckoutFunctions;

class Cart extends Model implements CartContract, Adjustable
{
	use CastsEnums;
	use HasAdjustmentsViaRelation;
	use RecalculatesAdjustments;
	use CheckoutFunctions;

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
			if ($this->hasItem($id)) {
				return true;
			}
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
			if ($qty > 0) {
				$item->quantity = $qty;
				$item->save();
			} else {
				$this->removeItem($item);
			}
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

	public function total(): float
	{
		$total = $this->itemsTotal() + $this->adjustments()->total();

		$clientCardAdjustment = $this->adjustments()->byType(AdjustmentTypeProxy::CLIENT_CARD())->first();

		if (isset($clientCardAdjustment)) {
			$total += abs(floatval($clientCardAdjustment->amount));
		}

		return $total;
	}

	public function totalWithCard(): float
	{
		return $this->itemsTotal() + $this->adjustments()->total();
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
}
