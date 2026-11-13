<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing;

\defined('_JEXEC') or die;

/** Collects assertions and messages of one test and derives its status. */
final class TestContext
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const WARN = 'warn';
    public const SKIP = 'skip';
    public const ERROR = 'error';

    /** @var array<int, array{level:string,text:string}> */
    private array $messages = [];
    private bool $failed = false;
    private bool $warned = false;

    public function ok(bool $condition, string $passMessage, ?string $failMessage = null): bool
    {
        if ($condition) {
            $this->messages[] = ['level' => 'ok', 'text' => $passMessage];

            return true;
        }

        $this->fail($failMessage ?? $passMessage);

        return false;
    }

    public function same(mixed $expected, mixed $actual, string $label): bool
    {
        if ($expected === $actual) {
            $this->messages[] = ['level' => 'ok', 'text' => $label . ': ' . self::show($actual)];

            return true;
        }

        $this->fail($label . ': ожидалось ' . self::show($expected) . ', получено ' . self::show($actual));

        return false;
    }

    public function info(string $text): void
    {
        $this->messages[] = ['level' => 'info', 'text' => $text];
    }

    public function warn(string $text): void
    {
        $this->warned = true;
        $this->messages[] = ['level' => 'warn', 'text' => $text];
    }

    public function fail(string $text): void
    {
        $this->failed = true;
        $this->messages[] = ['level' => 'fail', 'text' => $text];
    }

    public function skip(string $reason): never
    {
        throw new SkipException($reason);
    }

    public function status(): string
    {
        if ($this->failed) {
            return self::FAIL;
        }

        return $this->warned ? self::WARN : self::PASS;
    }

    public function messages(): array
    {
        return $this->messages;
    }

    public static function show(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            $text = (string) $value;

            return '"' . (strlen($text) > 160 ? substr($text, 0, 160) . '…' : $text) . '"';
        }

        return substr((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 200);
    }
}
