<?php

namespace App\Services;

final readonly class BindingResult
{
    public function __construct(
        /** The request comes from the machine the client is bound to. */
        public bool $matches,
        /** This request created the binding. */
        public bool $newlyBound,
    ) {}
}
