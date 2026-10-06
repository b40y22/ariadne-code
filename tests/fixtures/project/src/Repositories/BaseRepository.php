<?php

declare(strict_types=1);

namespace Shop\Repositories;

use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository
{
    public function __construct(protected Model $model) {}

    /** The model is a framework class, so the call ends in an `external` node. */
    public function create(array $attributes): array
    {
        return $this->model->create($attributes)->toArray();
    }
}
