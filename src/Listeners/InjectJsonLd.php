<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Listeners;

use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Twig\Events\PageEnded;
use CraftCms\Cms\View\Enums\Position;
use CraftCms\Cms\View\TemplateMode;
use JustinHoltWeb\Schemy\Plugin;
use JustinHoltWeb\Schemy\Services\Schema;

/**
 * Adds the page's JSON-LD to `<head>` at the end of every front-end page, unless the template
 * already called `craft.schemy.render()` or `disable()`.
 *
 * Twig layouts get the head placeholder automatically; Blade layouts need `@craftHead`.
 */
class InjectJsonLd
{
    public function __construct(private readonly Schema $schema) {}

    public function handle(PageEnded $event): void
    {
        if (! TemplateMode::is(TemplateMode::Site) || ! Plugin::getInstance()->getSettings()->autoInject) {
            return;
        }

        $script = $this->schema->pendingScript();

        if ($script !== null) {
            HtmlStack::html($script, Position::Head, 'schemy');
        }
    }
}
