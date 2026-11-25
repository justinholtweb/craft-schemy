<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Tests;

use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use JustinHoltWeb\Schemy\Tests\Support\CreatesContent;

abstract class TestCase extends PluginTestCase
{
    use CreatesContent;
}
