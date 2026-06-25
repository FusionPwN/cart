<?php

namespace Vanilo\Cart\Helpers;

use Illuminate\Support\Arr;
use Vanilo\Adjustments\Contracts\Adjustable;
use Vanilo\Adjustments\Contracts\Adjuster;
use Vanilo\Adjustments\Contracts\AdjustmentType;
use Vanilo\Adjustments\Models\AdjustmentTypeProxy;

class Modifier
{
	public AdjustmentType $type;
	public Adjustable $adjustable;
	public Adjuster $adjuster;
	public string $origin = '';
	public array $data = [];
	public string $title = '';
	public string $description = '';
	public float $amount = 0;


	public static function fromAttributes(array $attributes): self
	{
		$modifier = new self($attributes['adjuster'] ?? null);

		$modifier->type 			= $attributes['type'] ?? null;
		$modifier->adjustable 		= $attributes['adjustable'] ?? null;
		$modifier->adjuster 		= $attributes['adjuster'] ?? null;
		$modifier->origin 			= $attributes['origin'] ?? '';
		$modifier->data 			= $attributes['data'] ?? [];
		$modifier->title 			= $attributes['title'] ?? '';
		$modifier->description 		= $attributes['description'] ?? '';
		$modifier->amount 			= $attributes['amount'] ?? 0.0;

		return $modifier;
	}

	public function adjustable(): Adjustable
	{
		return $this->adjustable;
	}

	public function getType(): AdjustmentType
	{
		return $this->type;
	}

	public function getAdjustable(): Adjustable
	{
		return $this->adjustable;
	}

	public function getAdjuster(): Adjuster
	{
		return $this->adjuster;
	}

	public function getOrigin(): ?string
	{
		return $this->origin;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function getAmount(): float
	{
		return (float) $this->amount;
	}

	public function setAmount(float $amount): void
	{
		$this->amount = $amount;
	}

	public function getData(?string $key = null)
	{
		return Arr::get($this->data, $key);
	}

	public function isCharge(): bool
	{
		return $this->amount > 0;
	}

	public function isCredit(): bool
	{
		return $this->amount < 0;
	}

	public function isPromo(): bool
	{
		return AdjustmentTypeProxy::IsPromo($this->type);
	}

    public function getUniqueIdentifier(): string
    {
        return strtolower(class_basename($this->adjuster)) . '-' . $this->origin;
    }
}