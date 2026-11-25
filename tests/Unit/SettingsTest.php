<?php

declare(strict_types=1);

use CraftCms\Cms\Cp\Data\NavItem;
use JustinHoltWeb\Schemy\Models\Settings;
use JustinHoltWeb\Schemy\Plugin;
use JustinHoltWeb\Schemy\Services\Schema;

it('validates the sidebar icon and SVG', function (array $values, array $errorKeys) {
    $settings = new Settings($values);
    $settings->validate();

    expect(array_keys($settings->getFirstErrors()))->toBe($errorKeys);
})->with([
    'a real icon' => [['navIcon' => 'sitemap'], []],
    'an unknown icon' => [['navIcon' => 'not-a-real-icon-xyz'], ['navIcon']],
    'an icon name with spaces' => [['navIcon' => 'Bad Icon!'], ['navIcon']],
    'one svg element' => [['navIconSvg' => '<svg viewBox="0 0 10 10"><rect width="10" height="10"/></svg>'], []],
    'a file path' => [['navIconSvg' => '/etc/passwd'], ['navIconSvg']],
    'a long label' => [['navLabel' => str_repeat('x', 65)], ['navLabel']],
    'a bad identity type' => [['identityType' => 'Not a type'], ['identityType']],
]);

it('sanitizes a pasted SVG icon and applies the label', function () {
    $settings = new Settings([
        'navLabel' => 'Structured Data',
        'navIconSvg' => '<svg viewBox="0 0 10 10"><script>alert(1)</script><rect width="10" height="10" onclick="x()"/></svg>',
    ]);

    $item = Plugin::getInstance()->customiseNavItem(new NavItem()->label('Schemy'), $settings);

    expect($item->label)->toBe('Structured Data')
        ->and($item->iconSvg)->not->toContain('<script')
        ->and($item->iconSvg)->not->toContain('onclick')
        ->and($item->iconSvg)->toContain('<rect');
});

it('rolls a rejected save back, so later saves still work', function () {
    $schema = app(Schema::class);

    expect($schema->saveSettings(['navIcon' => 'not-a-real-icon-xyz']))->toBeFalse()
        ->and(Plugin::getInstance()->getSettings()->navIcon)->toBeNull()
        ->and($schema->saveSettings(['navLabel' => 'Structured Data']))->toBeTrue()
        ->and(Plugin::getInstance()->getSettings()->navLabel)->toBe('Structured Data');
});
