<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Events;

use CraftCms\Cms\Element\Contracts\ElementInterface;

/**
 * Dispatched before the JSON-LD graph is encoded. Change `$graph` to add, edit or remove nodes.
 *
 * ```php
 * Event::listen(function (GraphResolving $event) {
 *     $event->graph[] = ['@type' => 'SiteNavigationElement', 'name' => 'Main'];
 * });
 * ```
 *
 * Replaces Schemy 5's `Schema::EVENT_BEFORE_RENDER_GRAPH` / `GraphEvent`.
 */
class GraphResolving
{
    /**
     * @param  list<array<mixed>>  $graph  The nodes that will go in `@graph`
     */
    public function __construct(
        public readonly ?ElementInterface $element,
        public array $graph,
    ) {}
}
