<?php

declare(strict_types=1);

use CraftCms\Cms\Cms;
use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Mcp\McpActor;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\Request;
use JustinHoltWeb\Schemy\Mcp\SchemyTools;
use Mcp\Exception\ToolCallException;

function gql(array $scope, string $query): array
{
    $gql = app(Gql::class);
    $gql->invalidateCaches();

    return $gql->executeQuery(new GqlSchema(['name' => 'Test', 'uid' => 'test-'.md5(json_encode($scope)), 'scope' => $scope]), $query);
}

it('answers the schemy query only within the schema’s scope', function () {
    $entry = $this->createEntry();
    $site = 'sites.'.Sites::getPrimarySite()->uid.':read';
    $section = 'sections.'.$this->section()->uid.':read';
    $query = sprintf('{ a: schemy { jsonLd } b: schemy(uri: "%s") { jsonLd } }', $entry->uri);

    $full = gql([$site, $section, 'schemy.all:read'], $query)['data'];
    $noSection = gql([$site, 'schemy.all:read'], $query)['data'];

    expect($full['a']['jsonLd'])->toContain('WebSite')
        ->and($full['b']['jsonLd'])->toContain('NewsArticle')
        ->and($noSection['b'])->toBeNull()
        ->and(gql([$site, $section], '{ schemy { jsonLd } }'))->not->toHaveKey('data.schemy');
});

it('returns the field value and rendered JSON-LD over GraphQL', function () {
    $this->createEntry();
    $scope = ['sites.'.Sites::getPrimarySite()->uid.':read', 'sections.'.$this->section()->uid.':read'];

    $result = gql($scope, '{ entries(section: "news") { ... on article_Entry { seo { mode jsonLd } } } }');

    expect($result['data']['entries'][0]['seo']['mode'])->toBe('inherit')
        ->and($result['data']['entries'][0]['seo']['jsonLd'])->toContain('NewsArticle');
});

it('gives MCP clients an entry’s JSON-LD with validation, if they can view it', function () {
    $entry = $this->createEntry();
    $admin = $this->admin();
    $this->actingAs($admin, Cms::config()->authGuard);
    $request = Request::create('/');
    $request->setUserResolver(fn () => $admin);
    app()->instance(McpActor::class, new McpActor($request));

    $result = app(SchemyTools::class)->entryJsonLdGet($entry->id);

    expect($result['jsonLd']['@graph'][2]['@type'])->toBe('NewsArticle')
        ->and($result['validation'])->toHaveKey('valid');

    expect(fn () => app(SchemyTools::class)->entryJsonLdGet(999999))->toThrow(ToolCallException::class, 'Entry not found.');
});

it('validates from the console, failing on errors', function () {
    $this->createEntry();

    $this->artisan('schemy:validate', ['--section' => 'news'])->assertExitCode(0);
    $this->artisan('schemy:validate')->assertExitCode(2);
});
