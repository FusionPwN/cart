<?php

namespace Vanilo\Cart\Traits;

use Vanilo\Cart\Contracts\CartItem;
use Vanilo\Cart\Helpers\ModifierCollection;

trait HasModifiers
{
	public ModifierCollection $modifiers;

	public function __construct(array $attributes = [])
	{
		if ($this instanceof \Vanilo\Order\Models\Order) {
			// Set default status in case there was none given
			if (!isset($attributes['status'])) {
				$this->setDefaultOrderStatus();
			}
			
			parent::__construct($attributes);
		}

		$this->modifiers = new ModifierCollection($this);
	}

	public function modifiers(): ModifierCollection
	{
		return $this->modifiers;
	}

	public function adjustments(): ModifierCollection
	{
		return $this->modifiers();
	}

	public function resetModifiers(): void
	{
		$this->modifiers = new ModifierCollection($this);
	}
}