<?php

namespace Vanilo\Cart\Traits;

use Vanilo\Cart\Contracts\CartItem;
use Vanilo\Cart\Helpers\ModifierCollection;

trait HasModifiers
{
	public ModifierCollection $modifiers;

	public function __construct()
	{
		parent::__construct();

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
}