<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Listeners;

use CraftCms\Cms\Element\Events\ElementMetadataResolving;
use CraftCms\Cms\Support\Html;
use JustinHoltWeb\Schemy\Services\Schema;
use JustinHoltWeb\Schemy\Services\Validator;
use JustinHoltWeb\Schemy\Utilities\ValidatorUtility;
use Throwable;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * A "Schema" row in the element editor's sidebar: the main node's type and a validation summary
 * for the saved element, linking to the validator. Shown to people who can use the validator.
 */
class AddEditorMetadata
{
    public function __construct(
        private readonly Schema $schema,
        private readonly Validator $validator,
    ) {}

    public function handle(ElementMetadataResolving $event): void
    {
        $element = $event->element;

        if (! $element->id || $element->getIsDraft() || ! currentUser()?->can('utility:'.ValidatorUtility::id())) {
            return;
        }

        $schema = $this->schema;
        $override = $schema->getOverride($element);

        // Only elements Schemy describes: configured sections, or a Schema field on the layout
        if ($override === null && $schema->getInheritedConfig($element) === null) {
            return;
        }

        $event->metadata[t('Schema', category: 'schemy')] = function () use ($element): string|false {
            try {
                $document = $this->schema->getDocumentForElement($element);
            } catch (Throwable) {
                return false;
            }

            if ($document === null) {
                return Html::encode(t('Nothing output', category: 'schemy'));
            }

            $main = $this->schema->buildMainNode($element, $this->schema->getOverride($element));
            $result = $this->validator->validateDocument($document)->toArray();
            $summary = t('{errors, plural, =0{No errors} =1{1 error} other{# errors}}, {warnings, plural, =0{no warnings} =1{1 warning} other{# warnings}}', [
                'errors' => $result['errors'],
                'warnings' => $result['warnings'],
            ], category: 'schemy');

            $url = cp_url('utilities/'.ValidatorUtility::id(), ['entry' => $element->id, 'site' => $element->siteId]);

            return ($main ? Html::tag('code', Html::encode((string) $main['@type'])).' · ' : '')
                .Html::a(Html::encode($summary), $url);
        };
    }
}
