<?php

namespace App\Services\Import;

interface EntityStrategyInterface
{
    public function findExisting(array $row): ?\Illuminate\Database\Eloquent\Model;

    public function computeFieldDiffs(array $row, \Illuminate\Database\Eloquent\Model $existing): array;

    public function apply(array $row, ?\Illuminate\Database\Eloquent\Model $existing): \Illuminate\Database\Eloquent\Model;

    public function requiredFields(): array;
}
