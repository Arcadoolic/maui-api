<?php

namespace App\Services\Catalog;

final class CatalogImportResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public function received(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }

    /** @return array{received: int, created: int, updated: int, unchanged: int} */
    public function toArray(): array
    {
        return [
            'received' => $this->received(),
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
        ];
    }
}
