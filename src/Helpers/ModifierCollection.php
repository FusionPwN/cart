<?php

namespace Vanilo\Cart\Helpers;

use Illuminate\Support\Collection;
use Vanilo\Adjustments\Contracts\Adjustable;
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

	public function byType(AdjustmentType $type): Collection
	{
		$result = new self($this->adjustable_model);

		# $this->filters fucks up and puts the modifier on the adjustable model variable wtf had to do it this way...
		/* $filtered = $this->filter(function (Modifier $modifier) use ($type) {
			return $modifier->getType()->equals($type);
		}); */

		$filtered = array_filter($this->all(), function (Modifier $modifier) use ($type) {
			return $modifier->getType()->equals($type);
		});

		foreach ($filtered as $modifier) {
			$result->add($modifier);
		}

		return $result;
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
