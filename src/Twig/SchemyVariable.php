<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Twig;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Template;
use JustinHoltWeb\Schemy\Services\Schema;
use Twig\Markup;

/**
 * `craft.schemy` in Twig, `$craft->schemy` in Blade.
 */
class SchemyVariable
{
    public function __construct(private readonly Schema $schema) {}

    /**
     * Outputs the JSON-LD `<script>` tag and stops automatic output for this request.
     *
     * ```twig
     * {{ craft.schemy.render(entry) }}
     * ```
     *
     * ```blade
     * {!! $craft->schemy->render($entry) !!}
     * ```
     */
    public function render(?ElementInterface $element = null): Markup
    {
        return Template::raw($this->schema->renderScript($element));
    }

    /**
     * Returns the graph nodes as an array, for inspection or custom output.
     *
     * @return list<array<mixed>>
     */
    public function graph(?ElementInterface $element = null): array
    {
        return $this->schema->getGraph($element);
    }

    /**
     * Adds a node to this page's graph.
     *
     * ```twig
     * {% do craft.schemy.add({ '@type': 'Event', name: 'Launch party' }) %}
     * ```
     *
     * @param  array<mixed>  $node
     */
    public function add(array $node): void
    {
        $this->schema->addNode($node);
    }

    /**
     * Sets which element automatic output describes (useful for custom routes).
     * Pass `false` to output only the site-wide nodes.
     */
    public function setElement(ElementInterface|false|null $element): void
    {
        $this->schema->setElement($element);
    }

    /**
     * Turns off JSON-LD output for this request.
     */
    public function disable(): void
    {
        $this->schema->disable();
    }
}
