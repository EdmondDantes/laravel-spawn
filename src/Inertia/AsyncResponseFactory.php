<?php

namespace Spawn\Laravel\Inertia;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Inertia\ProvidesInertiaProperties;
use Inertia\Response;
use Inertia\ResponseFactory;
use Spawn\Laravel\Foundation\RequestContext;

/**
 * Coroutine-safe Inertia ResponseFactory.
 *
 * Before bootCompleted(): behaves like stock ResponseFactory.
 * After bootCompleted(): per-request state is stored in RequestContext.
 *
 * Isolated state: sharedProps, rootView, version, encryptHistory, urlResolver. The five
 * properties are unset on the shared object, so every read and write Inertia's own methods make
 * of them — render() among them — reaches __get/__set and the request's state. A render that
 * suspends, in a version closure that hashes the manifest or in the page check of
 * `inertia.pages.ensure_pages_exist`, therefore reads its own request's values when it resumes.
 */
class AsyncResponseFactory extends ResponseFactory
{
    private const CTX_KEY = 'inertia.state';

    /** The properties that belong to the request, kept in its InertiaState. */
    private const REQUEST_STATE = ['sharedProps', 'rootView', 'version', 'encryptHistory', 'urlResolver'];

    private bool $async = false;

    /** What boot configured, captured when async mode starts: each request starts from a copy of it. */
    private InertiaState $bootState;

    public function bootCompleted(): void
    {
        $this->bootState = new InertiaState();

        foreach (self::REQUEST_STATE as $property) {
            $this->bootState->$property = $this->$property;
            unset($this->$property);
        }

        $this->async = true;
    }

    /**
     * @return mixed a reference into this request's state, so that Inertia's own methods can
     *   modify its arrays in place
     */
    public function &__get(string $name)
    {
        $state = $this->getState();

        return $state->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->getState()->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->getState()->$name);
    }

    private function getState(): InertiaState
    {
        $ctx = RequestContext::current();
        $state = $ctx->find(self::CTX_KEY);

        if (! $state instanceof InertiaState) {
            $state = clone $this->bootState;
            $ctx->set(self::CTX_KEY, $state, replace: true);
        }

        return $state;
    }

    private function setState(string $key, mixed $value): void
    {
        $this->getState()->$key = $value;
    }

    public function share($key, $value = null): void
    {
        if (! $this->async) {
            parent::share($key, $value);
            return;
        }

        $state = $this->getState();
        $shared = $state->sharedProps;

        if (is_array($key)) {
            $shared = array_merge($shared, $key);
        } elseif ($key instanceof Arrayable) {
            $shared = array_merge($shared, $key->toArray());
        } elseif ($key instanceof ProvidesInertiaProperties) {
            $shared = array_merge($shared, [$key]);
        } else {
            Arr::set($shared, $key, $value);
        }

        $this->setState('sharedProps', $shared);
    }

    public function getShared(?string $key = null, $default = null)
    {
        if (! $this->async) {
            return parent::getShared($key, $default);
        }

        $shared = $this->getState()->sharedProps;

        if ($key) {
            return Arr::get($shared, $key, $default);
        }

        return $shared;
    }

    public function flushShared()
    {
        if (! $this->async) {
            parent::flushShared();
            return;
        }

        $this->setState('sharedProps', []);
    }

    public function setRootView(string $name): void
    {
        if (! $this->async) {
            parent::setRootView($name);
            return;
        }

        $this->setState('rootView', $name);
    }

    public function getRootView(): string
    {
        if (! $this->async) {
            return $this->rootView;
        }

        return $this->getState()->rootView;
    }

    public function version($version): void
    {
        if (! $this->async) {
            parent::version($version);
            return;
        }

        $this->setState('version', $version);
    }

    public function getVersion(): string
    {
        if (! $this->async) {
            return parent::getVersion();
        }

        $version = $this->getState()->version;

        if ($version instanceof Closure) {
            $version = app()->call($version);
        }

        return (string) ($version ?? '');
    }

    public function encryptHistory($encrypt = true): void
    {
        if (! $this->async) {
            parent::encryptHistory($encrypt);
            return;
        }

        $this->setState('encryptHistory', $encrypt);
    }

    public function resolveUrlUsing(?Closure $urlResolver = null): void
    {
        if (! $this->async) {
            parent::resolveUrlUsing($urlResolver);
            return;
        }

        $this->setState('urlResolver', $urlResolver);
    }
}
