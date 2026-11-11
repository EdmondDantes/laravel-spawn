<?php

namespace Spawn\Laravel\Inertia;

use Closure;

/**
 * The part of Inertia's ResponseFactory that belongs to one request, held in its context.
 */
final class InertiaState
{
    /** @var array<array-key, mixed> */
    public array $sharedProps = [];

    public string $rootView = 'app';

    /** A string, a Closure that answers one, or null. */
    public mixed $version = null;

    public ?bool $encryptHistory = null;

    public ?Closure $urlResolver = null;
}
