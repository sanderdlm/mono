<?php

namespace Mono\Test;

class BookDataTransferObject
{
    public function __construct(
        public string $title,
        public Gender $gender,
        public \DateTimeImmutable $published,
        public ?int $rating,
    ) {
    }
}
