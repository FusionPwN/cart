<?php

declare(strict_types=1);

namespace Vanilo\Cart;

use App\Events\UpdateCartState;
use App\Models\Admin\Coupon;
use App\Models\Admin\ShipmentMethod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Vanilo\Cart\Contracts\Cart as CartContract;
use Vanilo\Cart\Contracts\CartItem;
use Vanilo\Cart\Contracts\CartManager as CartManagerContract;
use Vanilo\Cart\Exceptions\InvalidCartConfigurationException;
use Vanilo\Cart\Models\Cart;
use Konekt\Address\Models\Country;
use Vanilo\Adjustments\Contracts\AdjustmentType;
use Vanilo\Adjustments\Models\Adjustment;
use Vanilo\Cart\Models\CartProxy;
use Vanilo\Contracts\Buyable;

class CartManager implements CartManagerContract
{
	public const CONFIG_SESSION_KEY = 'vanilo.cart.session_key';

	/** @var string The key in session that holds the cart id */
	protected $sessionKey;

	/** @var  Cart  The Cart model instance */
	protected $cart;

	public function __construct()
	{
		$this->sessionKey = config(self::CONFIG_SESSION_KEY);

		if (empty($this->sessionKey)) {
			throw new InvalidCartConfigurationException(
				sprintf(
					'Cart session key (`%s`) is empty. Please provide a valid value.',
					self::CONFIG_SESSION_KEY
				)
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getItems(): Collection
	{
		return $this->exists() ? $this->model()->getItems() : collect();
	}

	/**
	 * @inheritDoc
	 */
	public function getItemsDisplay(): Collection
	{
		return $this->exists() ? $this->model()->getItemsDisplay() : collect(['cart' => [], 'free' => []]);
	}

	/**
	 * @inheritDoc
	 */
	public function getItem($id): CartItem
	{
		return $this->exists() ? $this->model()->getItem($id) : false;
	}

	/**
	 * @inheritDoc
	 */
	public function hasItem($id)
	{
		return $this->exists() ? $this->model()->hasItem($id) : false;
	}

	/**
	 * @inheritDoc
	 */
	public function hasItems($ids): bool
	{
		return $this->exists() ? $this->model()->hasItems($ids) : false;
	}

	/**
	 * @inheritDoc
	 */
	public function addItem(Buyable $product, $qty = 1, $params = [])
	{
		$cart = $this->findOrCreateCart();

		return $cart->addItem($product, $qty, $params);
	}

	/**
	 * @inheritDoc
	 */
	public function setItemQty($item, $qty = 1)
	{
		$cart = $this->findOrCreateCart();

		return $cart->setItemQty($item, $qty);
	}

	/**
	 * @inheritDoc
	 */
	public function removeItem($item)
	{
		if ($cart = $this->model()) {
			$cart->removeItem($item);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function removeProduct(Buyable $product)
	{
		if ($cart = $this->model()) {
			$cart->removeProduct($product);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function clear()
	{
		if ($cart = $this->model()) {
			$cart->clear();
		}
	}

	/**
	 * @inheritDoc
	 */
	public function itemCount()
	{
		return $this->exists() ? $this->model()->itemCount() : 0;
	}

	/**
	 * @inheritDoc
	 */
	public function subTotal(): float
	{
		return $this->exists() ? $this->model()->subTotal() : 0;
	}

	/**
	 * @inheritDoc
	 */
	public function total(): float
	{
		return $this->exists() ? $this->model()->total() : 0;
	}

	/**
	 * @inheritDoc
	 */
	public function vatTotal(): float
	{
		return $this->exists() ? $this->model()->vatTotal() : 0;
	}

	/**
	 * @inheritDoc
	 */
	public function exists()
	{
		return (bool) $this->getCartId() && $this->model();
	}

	/**
	 * @inheritDoc
	 */
	public function doesNotExist()
	{
		return !$this->exists();
	}

	/**
	 * @inheritDoc
	 */
	public function model()
	{
		$id = $this->getCartId();

		if ($id && $this->cart) {
			return $this->cart;
		} elseif ($id) {
			$this->cart = CartProxy::find($id);

			return $this->cart;
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function isEmpty()
	{
		return 0 == $this->itemCount();
	}

	/**
	 * @inheritDoc
	 */
	public function isNotEmpty()
	{
		return !$this->isEmpty();
	}

	/**
	 * @inheritDoc
	 */
	public function destroy()
	{
		$this->clear();
		$this->model()->delete();
		$this->forget();
	}

	/**
	 * @inheritdoc
	 */
	public function create($forceCreateIfExists = false)
	{
		if ($this->exists() && !$forceCreateIfExists) {
			return;
		}

		$this->createCart();
	}

	/**
	 * @inheritDoc
	 */
	public function getUser()
	{
		return $this->exists() ? $this->model()->getUser() : null;
	}

	/**
	 * @inheritDoc
	 */
	public function setUser($user)
	{
		if ($this->exists()) {
			$this->cart->setUser($user);
			$this->cart->save();
			$this->cart->load('user');
		}
	}

	/**
	 * @inheritdoc
	 */
	public function removeUser()
	{
		$this->setUser(null);
	}

	public function restoreLastActiveCart($user)
	{
		$lastActiveCart = CartProxy::ofUser($user)->latest()->first();

		if ($lastActiveCart) {
			$this->setCartModel($lastActiveCart);
			event(new UpdateCartState($lastActiveCart));
		}
	}

	public function mergeLastActiveCartWithSessionCart($user)
	{
		/** @var Cart $lastActiveCart */
		if ($lastActiveCart = CartProxy::ofUser($user)->oldest()->first()) {
			if ($lastActiveCart->id != $this->cart->id) {
				/** @var CartItem $item */
				foreach ($lastActiveCart->getItems() as $item) {
					$this->addItem($item->getBuyable(), $item->getQuantity());
				}

				$lastActiveCart->delete();
			} else {
				$this->setCartModel($lastActiveCart);
				event(new UpdateCartState($lastActiveCart));
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function forget()
	{
		$this->cart = null;
		session()->forget($this->sessionKey);
	}

	/**
	 * Returns the model id of the cart for the current session
	 * or null if it does not exist
	 *
	 * @return int|null
	 */
	protected function getCartId()
	{
		return session($this->sessionKey);
	}

	/**
	 * Returns the cart model for the current session by either fetching it or creating one
	 *
	 * @return Cart
	 */
	protected function findOrCreateCart()
	{
		return $this->model() ?: $this->createCart();
	}

	/**
	 * Creates a new cart model and saves it's id in the session
	 */
	protected function createCart()
	{
		if (config('vanilo.cart.auto_assign_user') && Auth::guard('web')->check()) {
			$attributes = [
				'user_id' => Auth::guard('web')->user()->id
			];
		}

		return $this->setCartModel(CartProxy::create($attributes ?? []));
	}

	protected function setCartModel(CartContract $cart): CartContract
	{
		$this->cart = $cart;

		session([$this->sessionKey => $this->cart->id]);

		return $this->cart;
	}

	public function adjustments()
	{
		return $this->model()->adjustments();
	}

	public function getAdjustmentByType(AdjustmentType $type = null)
	{
		return $this->model()->getAdjustmentByType($type);
	}

	public function removeAdjustment(Adjustment $adjustment = null, AdjustmentType $type = null)
	{
		$this->model()->removeAdjustment($adjustment, $type);
	}

	public function setShipping(ShipmentMethod $shipping)
	{
		return $this->model()->setShipping($shipping);
	}

	public function setCountry(Country $country)
	{
		return $this->model()->setCountry($country);
	}

	public function updateShippingFee()
	{
		return $this->model()->updateShippingFee();
	}

	public function updateAdjustments()
	{
		return $this->model()->updateAdjustments();
	}

	public function getShippingAdjustment(): ?Adjustment
	{
		return $this->model()->getShippingAdjustment();
	}

	public function applyCoupon(Coupon $coupon)
	{
		return $this->exists() ? $this->model()->applyCoupon($coupon) : false;
	}

	public function removeCoupon()
	{
		return $this->exists() ? $this->model()->removeCoupon() : false;
	}

	public function getApplyableDiscounts(): array
	{
		return $this->exists() ? $this->model()->applyableDiscounts : [];
	}

	public function validateCoupon(Coupon $coupon)
	{
		return $this->exists() ? $this->model()->validateCoupon($coupon) : false;
	}

	public function validator()
	{
		return isset($this->model()->validator) ? $this->model()->validator : null;
	}

	public function getActiveCoupon(): ?Coupon
	{
		return isset($this->model()->activeCoupon) ? $this->model()->activeCoupon : null;
	}
}