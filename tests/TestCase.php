<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Las pruebas no dependen de los assets compilados (public/build) ni de npm run dev.
        $this->withoutVite();
    }
}
