<?php

namespace Mono\Test;

use Mono\Mono;
use PHPUnit\Framework\TestCase;

class TemplateFolderErrorTest extends TestCase
{
    public function testObjectCreationWithoutTemplateFolder(): void
    {
        $this->expectException(\RuntimeException::class);

        new Mono(__DIR__);
    }
}
