<?php

namespace Drutiny\Bulk\Attribute;

use Attribute;

#[Attribute]
class Queue {
    public function __construct(
        public readonly string $name
    ) {}
}