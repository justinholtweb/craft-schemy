<?php

namespace justinholtweb\schemy\variables;

use craft\base\ElementInterface;
use craft\helpers\Template;
use justinholtweb\schemy\Plugin;
use Twig\Markup;

/**
 * `craft.schemy` in templates.
 */
class SchemyVariable
{
    /**
     * Outputs the JSON-LD `<script>` tag and stops auto-injection for this request.
     *
     * ```twig
     * {{ craft.schemy.render(entry) }}
     * ```
     */
    public function render(?ElementInterface $element = null): Markup
    {
        return Template::raw(Plugin::getInstance()->schema->renderScript($element));
    }

    /**
     * Returns the graph nodes as an array, for inspection or custom output.
     */
    public function graph(?ElementInterface $element = null): array
    {
        return Plugin::getInstance()->schema->getGraph($element);
    }

    /**
     * Adds a node to this page's graph.
     *
     * ```twig
     * {% do craft.schemy.add({ '@type': 'Event', name: 'Launch party' }) %}
     * ```
     */
    public function add(array $node): void
    {
        Plugin::getInstance()->schema->addNode($node);
    }

    /**
     * Sets which element auto-injection describes (useful for custom routes).
     * Pass `false` to output only the site-wide nodes.
     */
    public function setElement(ElementInterface|false|null $element): void
    {
        Plugin::getInstance()->schema->setElement($element);
    }

    /**
     * Turns off JSON-LD output for this request.
     */
    public function disable(): void
    {
        Plugin::getInstance()->schema->disable();
    }
}
