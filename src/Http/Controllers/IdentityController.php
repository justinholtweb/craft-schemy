<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Http\Controllers;

use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Ui\Controls\Combobox;
use CraftCms\Cms\Ui\Enums\ControlMode;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\MarkdownContent;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Illuminate\Http\Request;
use JustinHoltWeb\Schemy\Helpers\PropertyRows;
use JustinHoltWeb\Schemy\Helpers\PropertyTable;
use JustinHoltWeb\Schemy\Helpers\SchemaTypes;
use JustinHoltWeb\Schemy\Plugin;
use JustinHoltWeb\Schemy\Services\Schema;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * The site identity node: who is behind the site.
 */
readonly class IdentityController
{
    use RespondsWithFlash;

    public function __construct(
        private Schema $schema,
        private GeneralConfig $generalConfig,
    ) {}

    public function edit(): CpScreenResponse
    {
        $settings = Plugin::getInstance()->getSettings();

        return $this->screen([
            'type' => $settings->identityType ?? 'Organization',
            'properties' => PropertyRows::normalize($settings->identity),
        ], $settings->errors()->getMessages());
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

        if (! $this->schema->saveSettings(['identityType' => $type, 'identity' => $properties])) {
            return $this->screen(['type' => $type, 'properties' => $properties], Plugin::getInstance()->getSettings()->errors()->getMessages());
        }

        return $this->asSuccess(t('Site identity saved.', category: 'schemy'), redirect: cp_url('schemy/identity'));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, list<string>>  $errors
     */
    private function screen(array $values, array $errors = []): CpScreenResponse
    {
        $editable = $this->generalConfig->allowAdminChanges;
        $nodes = [];

        if (! $editable) {
            $nodes[] = Callout::make('schemy-readonly', t('Schemy settings are stored in project config, and `allowAdminChanges` is disabled in this environment. Make changes in your development environment and deploy them.', category: 'schemy'))
                ->variant('warning');
        }

        $nodes[] = MarkdownContent::make('schemy-identity-intro', t('Describes who is behind the site. It’s output on every page and set as the `publisher` of articles and other creative works. Values are rendered per site, so `{{ siteName }}` and `{{ currentSite.handle }}` work as expected.', category: 'schemy'));
        $nodes[] = Field::make(t('Identity type', category: 'schemy'), Combobox::make('type')->options(PropertyTable::typeOptions(SchemaTypes::IDENTITY_TYPES))->placeholder('Organization'))
            ->instructions(t('Usually Organization, a more specific business type, or Person.', category: 'schemy'));
        $nodes[] = Field::make(t('Properties', category: 'schemy'), PropertyTable::make('properties', [], [], $values['type'] ?? null))
            ->instructions(t('Each value is a template or plain text, for example `logo` → `{{ craft.assets.filename(\'logo.png\').one().url ?? \'\' }}`, `sameAs` → a profile URL, `address.@type` → `PostalAddress`.', category: 'schemy'));

        return new CpScreenResponse()
            ->title(t('Site Identity', category: 'schemy'))
            ->ui(Ui::make($nodes), new UiContext(
                values: $values,
                errors: $errors,
                mode: $editable ? ControlMode::Editable : ControlMode::ReadOnly,
            ), props: [
                'readOnly' => ! $editable,
                'submit' => $editable ? ['method' => 'post', 'url' => cp_url('schemy/identity')] : null,
            ]);
    }
}
