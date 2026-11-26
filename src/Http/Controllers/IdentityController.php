<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Http\Controllers;

use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Ui\Controls\Combobox;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\MarkdownContent;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Illuminate\Http\Request;
use JustinHoltWeb\Schemy\Helpers\PropertyRows;
use JustinHoltWeb\Schemy\Helpers\PropertyTable;
use JustinHoltWeb\Schemy\Helpers\SchemaTypes;
use JustinHoltWeb\Schemy\Services\Store;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * The site identity node: who is behind the site. For users with the `schemy-editIdentity`
 * permission (see `routes/cp.php`), in any environment: it's stored in Schemy's table.
 */
readonly class IdentityController
{
    use RespondsWithFlash;

    public function __construct(
        private Store $store,
    ) {}

    public function edit(): CpScreenResponse
    {
        $identity = $this->store->getIdentity();

        return $this->screen(['type' => $identity['type'], 'properties' => $identity['properties']], $identity['untrusted']);
    }

    public function save(Request $request): Response|CpScreenResponse
    {
        $type = SchemaTypes::cleanName($request->input('type')) ?: 'Organization';
        // Identity values are always templates, rendered per site
        $rows = array_map(
            fn (array $row) => ['source' => PropertyRows::SOURCE_TEMPLATE] + $row,
            array_filter((array) $request->input('properties', []), 'is_array'),
        );
        $properties = PropertyRows::normalize($rows);

        $this->store->saveIdentity($type, $properties, SectionsController::vouch($request));

        return $this->asSuccess(t('Site identity saved.', category: 'schemy'), redirect: cp_url('schemy/identity'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function screen(array $values, bool $untrusted): CpScreenResponse
    {
        $nodes = [];

        if ($untrusted) {
            array_push($nodes, ...SectionsController::untrustedNodes());
        }

        $nodes[] = MarkdownContent::make('schemy-identity-intro', t('Describes who is behind the site. It’s output on every page and set as the `publisher` of articles and other creative works. Values are rendered per site, so `{{ siteName }}` and `{{ currentSite.handle }}` work as expected.', category: 'schemy'));
        $nodes[] = Field::make(t('Identity type', category: 'schemy'), Combobox::make('type')->options(PropertyTable::typeOptions(SchemaTypes::IDENTITY_TYPES))->placeholder('Organization'))
            ->instructions(t('Usually Organization, a more specific business type, or Person.', category: 'schemy'));
        $nodes[] = Field::make(t('Properties', category: 'schemy'), PropertyTable::make('properties', [], [], $values['type'] ?? null))
            ->instructions(t('Each value is a template or plain text, for example `logo` → `{{ craft.assets.filename(\'logo.png\').one().url ?? \'\' }}`, `sameAs` → a profile URL, `address.@type` → `PostalAddress`.', category: 'schemy'));

        return new CpScreenResponse()
            ->title(t('Site Identity', category: 'schemy'))
            ->ui(Ui::make($nodes), new UiContext(values: $values), props: [
                'submit' => ['method' => 'post', 'url' => cp_url('schemy/identity')],
            ]);
    }
}
