<?php

namespace justinholtweb\schemy\events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Fired before the JSON-LD graph is encoded. Modify `$graph` to add,
 * change, or remove nodes.
 */
class GraphEvent extends Event
{
    public ?ElementInterface $element = null;

    /** @var array[] The list of nodes that will go in `@graph`. */
    public array $graph = [];
}
