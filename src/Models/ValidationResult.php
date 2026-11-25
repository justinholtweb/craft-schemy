<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Models;

/**
 * The outcome of validating a JSON-LD document.
 */
final class ValidationResult
{
    public const string ERROR = 'error';

    public const string WARNING = 'warning';

    public const string NOTICE = 'notice';

    /**
     * @var array<array{severity: string, path: string, message: string}>
     */
    public array $issues = [];

    /**
     * @param  array|null  $document  The document that was validated, when it could be decoded
     */
    public function __construct(public ?array $document = null) {}

    public function add(string $severity, string $path, string $message): void
    {
        $this->issues[] = ['severity' => $severity, 'path' => $path, 'message' => $message];
    }

    public function count(string $severity): int
    {
        return count(array_filter($this->issues, fn (array $issue) => $issue['severity'] === $severity));
    }

    public function hasErrors(): bool
    {
        return $this->count(self::ERROR) > 0;
    }

    /**
     * Issues sorted errors first, then warnings, then notices, keeping document order within each.
     */
    public function sortedIssues(): array
    {
        $rank = [self::ERROR => 0, self::WARNING => 1, self::NOTICE => 2];
        $issues = $this->issues;
        $order = array_keys($issues);

        array_multisort(
            array_map(fn (array $issue) => $rank[$issue['severity']] ?? 3, $issues),
            $order,
            $issues,
        );

        return $issues;
    }

    public function toArray(): array
    {
        return [
            'valid' => ! $this->hasErrors(),
            'errors' => $this->count(self::ERROR),
            'warnings' => $this->count(self::WARNING),
            'notices' => $this->count(self::NOTICE),
            'issues' => $this->sortedIssues(),
        ];
    }
}
