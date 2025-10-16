<?php

namespace Vanilo\Cart\Helpers;

use Illuminate\Support\Collection;
use Vanilo\Adjustments\Contracts\Adjuster;
use Vanilo\Adjustments\Contracts\AdjustmentType;

class ModifierCollection extends Collection
{
	private $adjustable_model;

	public function __construct($adjustable_model)
	{
		$this->adjustable_model = $adjustable_model;
	}

	public function adjustable()
	{
		return $this->adjustable_model;
	}

	public function create(Adjuster $adjuster): void
	{
		$modifier = Modifier::fromAttributes($adjuster->getModelAttributes($this->adjustable_model));

		$this->add($modifier);
	}

	public function byType(string|AdjustmentType $type): ?Collection
	{
		if (is_string($type)) {
			return $this->filter(fn(Modifier $modifier) => $modifier->getType()->value === $type);
		} else {
			return $this->filter(fn(Modifier $modifier) => $modifier->getType()->equals($type));
		}
	}

	public function total(array $excludes = []): float
	{
		$collection = $this;

		if (count($excludes) > 0) {
			$collection = $this->filter(function (Modifier $modifier) use ($excludes) {
				return in_array($modifier->getType(), $excludes, true);
			});
		}

		return floatval($collection->sum('amount'));
	}
}