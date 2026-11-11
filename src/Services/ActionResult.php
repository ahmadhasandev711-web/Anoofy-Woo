<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

/** Outcome of a user action. `code` is either a TransitionError value or one of the codes below (never free text). */
final class ActionResult
{
    public const OK = 'OK';
    public const NOT_FOUND = 'NOT_FOUND';
    public const FORBIDDEN = 'FORBIDDEN';
    public const CONCURRENT_CHANGE = 'CONCURRENT_CHANGE';
    public const INVALID_ASSIGNEE = 'INVALID_ASSIGNEE';
    public const INVALID_INPUT = 'INVALID_INPUT';
    public const EFFECT_FAILED = 'EFFECT_FAILED';
    public const CONNECTION_FAILED = 'CONNECTION_FAILED';

    private function __construct(public readonly bool $ok, public readonly string $code)
    {
    }

    public static function ok(string $code = self::OK): self
    {
        return new self(true, $code);
    }

    public static function fail(string $code): self
    {
        return new self(false, $code);
    }
}
