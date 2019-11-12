<?php

class Container
{
    /**
     * The current globally available container (if any).
     *
     * @var static
     */
    protected static $instance;

    /**
     * An array of the types that have been resolved.
     *
     * @var array
     */
    protected $resolved = [];

    /**
     * The container's bindings.
     *
     * @var array
     */
    protected $bindings = [];

    /**
     * The container's method bindings.
     *
     * @var array
     */
    protected $methodBindings = [];

    /**
     * The container's shared instances.
     *
     * @var array
     */
    protected $instances = [];

    /**
     * The registered type aliases.
     *
     * @var array
     */
    protected $aliases = [];

    /**
     * The registered aliases keyed by the abstract name.
     *
     * @var array
     */
    protected $abstractAliases = [];

    /**
     * The extension closures for services.
     *
     * @var array
     */
    protected $extenders = [];

    /**
     * All of the registered tags.
     *
     * @var array
     */
    protected $tags = [];

    /**
     * The stack of concretions currently being built.
     *
     * @var array
     */
    protected $buildStack = [];

    /**
     * The parameter override stack.
     *
     * @var array
     */
    protected $with = [];

    /**
     * The contextual binding map.
     *
     * @var array
     */
    public $contextual = [];

    /**
     * All of the registered rebound callbacks.
     *
     * @var array
     */
    protected $reboundCallbacks = [];

    /**
     * All of the global resolving callbacks.
     *
     * @var array
     */
    protected $globalResolvingCallbacks = [];

    /**
     * All of the global after resolving callbacks.
     *
     * @var array
     */
    protected $globalAfterResolvingCallbacks = [];

    /**
     * All of the resolving callbacks by class type.
     *
     * @var array
     */
    protected $resolvingCallbacks = [];

    /**
     * All of the after resolving callbacks by class type.
     *
     * @var array
     */
    protected $afterResolvingCallbacks = [];

    /**
     * Define a contextual binding.
     *
     * @param  string  $concrete
     * @return \Illuminate\Contracts\Container\ContextualBindingBuilder
     */
    public function when($concrete)
    {
        return new ContextualBindingBuilder($this, $this->getAlias($concrete));
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * @param  string  $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        return isset($this->bindings[$abstract]) ||
               isset($this->instances[$abstract]) ||
               $this->isAlias($abstract);
    }

    /**
     *  {@inheritdoc}
     */
    public function has($id)
    {
        return $this->bound($id);
    }

    /**
     * Determine if the given abstract type has been resolved.
     *
     * @param  string  $abstract
     * @return bool
     */
    public function resolved($abstract)
    {
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        return isset($this->resolved[$abstract]) ||
               isset($this->instances[$abstract]);
    }

    /**
     * Determine if a given type is shared.
     *
     * @param  string  $abstract
     * @return bool
     */
    public function isShared($abstract)
    {
        return isset($this->instances[$abstract]) ||
              (isset($this->bindings[$abstract]['shared']) &&
               $this->bindings[$abstract]['shared'] === true);
    }

    /**
     * Determine if a given string is an alias.
     *
     * @param  string  $name
     * @return bool
     */
    public function isAlias($name)
    {
        return isset($this->aliases[$name]);
    }

    /**
     * Register a binding with the container.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {
        // If no concrete type was given, we will simply set the concrete type to the
        // abstract type. After that, the concrete type to be registered as shared
        // without being forced to state their classes in both of the parameters.
        $this->dropStaleInstances($abstract);

        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // If the factory is not a Closure, it means it is just a class name which is
        // bound into this container to the abstract type and we will just wrap it
        // up inside its own Closure to give us more convenience when extending.
        if (! $concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');

        // If the abstract type was already resolved in this container we'll fire the
        // rebound listener so that any objects which have already gotten resolved
        // can have their copy of the object updated via the listener callbacks.
        if ($this->resolved($abstract)) {
            $this->rebound($abstract);
        }
    }

    /**
     * Get the Closure to be used when building a type.
     *
     * @param  string  $abstract
     * @param  string  $concrete
     * @return \Closure
     */
    protected function getClosure($abstract, $concrete)
    {
        return function ($container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete);
            }
            
            return $container->make($concrete, $parameters);
        };
    }

    /**
     * Determine if the container has a method binding.
     *
     * @param  string  $method
     * @return bool
     */
    public function hasMethodBinding($method)
    {
        return isset($this->methodBindings[$method]);
    }

    /**
     * Bind a callback to resolve with Container::call.
     *
     * @param  string  $method
     * @param  \Closure  $callback
     * @return void
     */
    public function bindMethod($method, $callback)
    {
        $this->methodBindings[$method] = $callback;
    }

    /**
     * Get the method binding for the given method.
     *
     * @param  string  $method
     * @param  mixed  $instance
     * @return mixed
     */
    public function callMethodBinding($method, $instance)
    {
        return call_user_func($this->methodBindings[$method], $instance, $this);
    }

    /**
     * Add a contextual binding to the container.
     *
     * @param  string  $concrete
     * @param  string  $abstract
     * @param  \Closure|string  $implementation
     * @return void
     */
    public function addContextualBinding($concrete, $abstract, $implementation)
    {
        $this->contextual[$concrete][$this->getAlias($abstract)] = $implementation;
    }

    /**
     * Register a binding if it hasn't already been registered.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bindIf($abstract, $concrete = null, $shared = false)
    {
        if (! $this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * Register a shared binding in the container.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * "Extend" an abstract type in the container.
     *
     * @param  string    $abstract
     * @param  \Closure  $closure
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function extend($abstract, Closure $closure)
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);

            $this->rebound($abstract);
        } else {
            $this->extenders[$abstract][] = $closure;

            if ($this->resolved($abstract)) {
                $this->rebound($abstract);
            }
        }
    }

    /**
     * Register an existing instance as shared in the container.
     *
     * @param  string  $abstract
     * @param  mixed   $instance
     * @return mixed
     */
    public function instance($abstract, $instance)
    {
        $this->removeAbstractAlias($abstract);

        $isBound = $this->bound($abstract);

        unset($this->aliases[$abstract]);

        // We'll check to determine if this type has been bound before, and if it has
        // we will fire the rebound callbacks registered with the container and it
        // can be updated with consuming classes that have gotten resolved here.
        $this->instances[$abstract] = $instance;

        if ($isBound) {
            $this->rebound($abstract);
        }

        return $instance;
    }

    /**
     * Remove an alias from the contextual binding alias cache.
     *
     * @param  string  $searched
     * @return void
     */
    protected function removeAbstractAlias($searched)
    {
        if (! isset($this->aliases[$searched])) {
            return;
        }

        foreach ($this->abstractAliases as $abstract => $aliases) {
            foreach ($aliases as $index => $alias) {
                if ($alias == $searched) {
                    unset($this->abstractAliases[$abstract][$index]);
                }
            }
        }
    }

    /**
     * Assign a set of tags to a given binding.
     *
     * @param  array|string  $abstracts
     * @param  array|mixed   ...$tags
     * @return void
     */
    public function tag($abstracts, $tags)
    {
        $tags = is_array($tags) ? $tags : array_slice(func_get_args(), 1);

        foreach ($tags as $tag) {
            if (! isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            foreach ((array) $abstracts as $abstract) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     *
     * @param  string  $tag
     * @return array
     */
    public function tagged($tag)
    {
        $results = [];

        if (isset($this->tags[$tag])) {
            foreach ($this->tags[$tag] as $abstract) {
                $results[] = $this->make($abstract);
            }
        }

        return $results;
    }

    /**
     * Alias a type to a different name.
     *
     * @param  string  $abstract
     * @param  string  $alias
     * @return void
     */
    public function alias($abstract, $alias)
    {
        $this->aliases[$alias] = $abstract;

        $this->abstractAliases[$abstract][] = $alias;
    }

    /**
     * Bind a new callback to an abstract's rebind event.
     *
     * @param  string    $abstract
     * @param  \Closure  $callback
     * @return mixed
     */
    public function rebinding($abstract, Closure $callback)
    {
        $this->reboundCallbacks[$abstract = $this->getAlias($abstract)][] = $callback;

        if ($this->bound($abstract)) {
            return $this->make($abstract);
        }
    }

    /**
     * Refresh an instance on the given target and method.
     *
     * @param  string  $abstract
     * @param  mixed   $target
     * @param  string  $method
     * @return mixed
     */
    public function refresh($abstract, $target, $method)
    {
        return $this->rebinding($abstract, function ($app, $instance) use ($target, $method) {
            $target->{$method}($instance);
        });
    }

    /**
     * Fire the "rebound" callbacks for the given abstract type.
     *
     * @param  string  $abstract
     * @return void
     */
    protected function rebound($abstract)
    {
        $instance = $this->make($abstract);
            
        foreach ($this->getReboundCallbacks($abstract) as $callback) {
            call_user_func($callback, $this, $instance);
        }
    }

    /**
     * Get the rebound callbacks for a given type.
     *
     * @param  string  $abstract
     * @return array
     */
    protected function getReboundCallbacks($abstract)
    {
        if (isset($this->reboundCallbacks[$abstract])) {
            return $this->reboundCallbacks[$abstract];
        }

        return [];
    }

    /**
     * Wrap the given closure such that its dependencies will be injected when executed.
     *
     * @param  \Closure  $callback
     * @param  array  $parameters
     * @return \Closure
     */
    public function wrap(Closure $callback, array $parameters = [])
    {
        return function () use ($callback, $parameters) {
            return $this->call($callback, $parameters);
        };
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     */
    public function call($callback, array $parameters = [], $defaultMethod = null)
    {
        return BoundMethod::call($this, $callback, $parameters, $defaultMethod);
    }

    /**
     * Get a closure to resolve the given type from the container.
     *
     * @param  string  $abstract
     * @return \Closure
     */
    public function factory($abstract)
    {
        return function () use ($abstract) {
            return $this->make($abstract);
        };
    }

    /**
     * An alias function name for make().
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function makeWith($abstract, array $parameters = [])
    {
        return $this->make($abstract, $parameters);
    }

    /**
     * Resolve the given type from the container.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = [])
    {
        return $this->resolve($abstract, $parameters);
    }

    /**
     *  {@inheritdoc}
     */
    public function get($id)
    {
        if ($this->has($id)) {
            return $this->resolve($id);
        }

        throw new Exception;
    }

    /**
     * Resolve the given type from the container.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    protected function resolve($abstract, $parameters = [])
    {
        $abstract = $this->getAlias($abstract);

        $needsContextualBuild = ! empty($parameters) || ! is_null(
            $this->getContextualConcrete($abstract)
        );

        // If an instance of the type is currently being managed as a singleton we'll
        // just return an existing instance instead of instantiating new instances
        // so the developer can keep using the same objects instance every time.
        if (isset($this->instances[$abstract]) && ! $needsContextualBuild) {
            return $this->instances[$abstract];
        }

        $this->with[] = $parameters;

        $concrete = $this->getConcrete($abstract);

        // We're ready to instantiate an instance of the concrete type registered for
        // the binding. This will instantiate the types, as well as resolve any of
        // its "nested" dependencies recursively until all have gotten resolved.
        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete);
        } else {
            $object = $this->make($concrete);
        }

        // If we defined any extenders for this type, we'll need to spin through them
        // and apply them to the object being built. This allows for the extension
        // of services, such as changing configuration or decorating the object.
        foreach ($this->getExtenders($abstract) as $extender) {
            $object = $extender($object, $this);
        }

        // If the requested type is registered as a singleton we'll want to cache off
        // the instances in "memory" so we can return it later without creating an
        // entirely new instance of an object on each subsequent request for it.
        if ($this->isShared($abstract) && ! $needsContextualBuild) {
            $this->instances[$abstract] = $object;
        }

        $this->fireResolvingCallbacks($abstract, $object);

        // Before returning, we will also set the resolved flag to "true" and pop off
        // the parameter overrides for this build. After those two things are done
        // we will be ready to return back the fully constructed class instance.
        $this->resolved[$abstract] = true;

        array_pop($this->with);

        return $object;
    }

    /**
     * Get the concrete type for a given abstract.
     *
     * @param  string  $abstract
     * @return mixed   $concrete
     */
    protected function getConcrete($abstract)
    {
        if (! is_null($concrete = $this->getContextualConcrete($abstract))) {
            return $concrete;
        }

        // If we don't have a registered resolver or concrete for the type, we'll just
        // assume each type is a concrete name and will attempt to resolve it as is
        // since the container should be able to resolve concretes automatically.
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    /**
     * Get the contextual concrete binding for the given abstract.
     *
     * @param  string  $abstract
     * @return string|null
     */
    protected function getContextualConcrete($abstract)
    {
        if (! is_null($binding = $this->findInContextualBindings($abstract))) {
            return $binding;
        }

        // Next we need to see if a contextual binding might be bound under an alias of the
        // given abstract type. So, we will need to check if any aliases exist with this
        // type and then spin through them and check for contextual bindings on these.
        if (empty($this->abstractAliases[$abstract])) {
            return;
        }

        foreach ($this->abstractAliases[$abstract] as $alias) {
            if (! is_null($binding = $this->findInContextualBindings($alias))) {
                return $binding;
            }
        }
    }

    /**
     * Find the concrete binding for the given abstract in the contextual binding array.
     *
     * @param  string  $abstract
     * @return string|null
     */
    protected function findInContextualBindings($abstract)
    {
        if (isset($this->contextual[end($this->buildStack)][$abstract])) {
            return $this->contextual[end($this->buildStack)][$abstract];
        }
    }

    /**
     * Determine if the given concrete is buildable.
     *
     * @param  mixed   $concrete
     * @param  string  $abstract
     * @return bool
     */
    protected function isBuildable($concrete, $abstract)
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * Instantiate a concrete instance of the given type.
     *
     * @param  string  $concrete
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function build($concrete)
    {
        // If the concrete type is actually a Closure, we will just execute it and
        // hand back the results of the functions, which allows functions to be
        // used as resolvers for more fine-tuned resolution of these objects.
        if ($concrete instanceof Closure) {
            return $concrete($this, $this->getLastParameterOverride());
        }

        $reflector = new ReflectionClass($concrete);

        // If the type is not instantiable, the developer is attempting to resolve
        // an abstract type such as an Interface of Abstract Class and there is
        // no binding registered for the abstractions so we need to bail out.
        if (! $reflector->isInstantiable()) {
            return $this->notInstantiable($concrete);
        }

        $this->buildStack[] = $concrete;

        $constructor = $reflector->getConstructor();

        // If there are no constructors, that means there are no dependencies then
        // we can just resolve the instances of the objects right away, without
        // resolving any other types or dependencies out of these containers.
        if (is_null($constructor)) {
            array_pop($this->buildStack);

            return new $concrete;
        }

        $dependencies = $constructor->getParameters();

        // Once we have all the constructor's parameters we can create each of the
        // dependency instances and then use the reflection instances to make a
        // new instance of this class, injecting the created dependencies in.
        $instances = $this->resolveDependencies(
            $dependencies
        );

        array_pop($this->buildStack);

        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve all of the dependencies from the ReflectionParameters.
     *
     * @param  array  $dependencies
     * @return array
     */
    protected function resolveDependencies(array $dependencies)
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            // If this dependency has a override for this particular build we will use
            // that instead as the value. Otherwise, we will continue with this run
            // of resolutions and let reflection attempt to determine the result.
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);

                continue;
            }

            // If the class is null, it means the dependency is a string or some other
            // primitive type which we can not resolve since it is not a class and
            // we will just bomb out with an error since we have no-where to go.
            $results[] = is_null($dependency->getClass())
                            ? $this->resolvePrimitive($dependency)
                            : $this->resolveClass($dependency);
        }

        return $results;
    }

    /**
     * Determine if the given dependency has a parameter override.
     *
     * @param  \ReflectionParameter  $dependency
     * @return bool
     */
    protected function hasParameterOverride($dependency)
    {
        return array_key_exists(
            $dependency->name, $this->getLastParameterOverride()
        );
    }

    /**
     * Get a parameter override for a dependency.
     *
     * @param  \ReflectionParameter  $dependency
     * @return mixed
     */
    protected function getParameterOverride($dependency)
    {
        return $this->getLastParameterOverride()[$dependency->name];
    }

    /**
     * Get the last parameter override.
     *
     * @return array
     */
    protected function getLastParameterOverride()
    {
        return count($this->with) ? end($this->with) : [];
    }

    /**
     * Resolve a non-class hinted primitive dependency.
     *
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolvePrimitive(ReflectionParameter $parameter)
    {
        if (! is_null($concrete = $this->getContextualConcrete('$'.$parameter->name))) {
            return $concrete instanceof Closure ? $concrete($this) : $concrete;
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $this->unresolvablePrimitive($parameter);
    }

    /**
     * Resolve a class based dependency from the container.
     *
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $this->make($parameter->getClass()->name);
        }

        // If we can not resolve the class instance, we will check to see if the value
        // is optional, and if it is we will return the optional parameter value as
        // the value of the dependency, similarly to how we do this with scalars.
        catch (Exception $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }

    /**
     * Throw an exception that the concrete is not instantiable.
     *
     * @param  string  $concrete
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function notInstantiable($concrete)
    {
        if (! empty($this->buildStack)) {
            $previous = implode(', ', $this->buildStack);

            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }

        throw new Exception($message);
    }

    /**
     * Throw an exception for an unresolvable primitive.
     *
     * @param  \ReflectionParameter  $parameter
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function unresolvablePrimitive(ReflectionParameter $parameter)
    {
        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

        throw new Exception($message);
    }

    /**
     * Register a new resolving callback.
     *
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function resolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if (is_null($callback) && $abstract instanceof Closure) {
            $this->globalResolvingCallbacks[] = $abstract;
        } else {
            $this->resolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a new after resolving callback for all types.
     *
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalAfterResolvingCallbacks[] = $abstract;
        } else {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Fire all of the resolving callbacks.
     *
     * @param  string  $abstract
     * @param  mixed   $object
     * @return void
     */
    protected function fireResolvingCallbacks($abstract, $object)
    {
        $this->fireCallbackArray($object, $this->globalResolvingCallbacks);

        $this->fireCallbackArray(
            $object, $this->getCallbacksForType($abstract, $object, $this->resolvingCallbacks)
        );

        $this->fireAfterResolvingCallbacks($abstract, $object);
    }

    /**
     * Fire all of the after resolving callbacks.
     *
     * @param  string  $abstract
     * @param  mixed   $object
     * @return void
     */
    protected function fireAfterResolvingCallbacks($abstract, $object)
    {
        $this->fireCallbackArray($object, $this->globalAfterResolvingCallbacks);

        $this->fireCallbackArray(
            $object, $this->getCallbacksForType($abstract, $object, $this->afterResolvingCallbacks)
        );
    }

    /**
     * Get all callbacks for a given type.
     *
     * @param  string  $abstract
     * @param  object  $object
     * @param  array   $callbacksPerType
     *
     * @return array
     */
    protected function getCallbacksForType($abstract, $object, array $callbacksPerType)
    {
        $results = [];

        foreach ($callbacksPerType as $type => $callbacks) {
            if ($type === $abstract || $object instanceof $type) {
                $results = array_merge($results, $callbacks);
            }
        }

        return $results;
    }

    /**
     * Fire an array of callbacks with an object.
     *
     * @param  mixed  $object
     * @param  array  $callbacks
     * @return void
     */
    protected function fireCallbackArray($object, array $callbacks)
    {
        foreach ($callbacks as $callback) {
            $callback($object, $this);
        }
    }

    /**
     * Get the container's bindings.
     *
     * @return array
     */
    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * Get the alias for an abstract if available.
     *
     * @param  string  $abstract
     * @return string
     *
     * @throws \LogicException
     */
    public function getAlias($abstract)
    {
        if (! isset($this->aliases[$abstract])) {
            return $abstract;
        }

        if ($this->aliases[$abstract] === $abstract) {
            throw new LogicException("[{$abstract}] is aliased to itself.");
        }

        return $this->getAlias($this->aliases[$abstract]);
    }

    /**
     * Get the extender callbacks for a given type.
     *
     * @param  string  $abstract
     * @return array
     */
    protected function getExtenders($abstract)
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->extenders[$abstract])) {
            return $this->extenders[$abstract];
        }

        return [];
    }

    /**
     * Remove all of the extender callbacks for a given type.
     *
     * @param  string  $abstract
     * @return void
     */
    public function forgetExtenders($abstract)
    {
        unset($this->extenders[$this->getAlias($abstract)]);
    }

    /**
     * Drop all of the stale instances and aliases.
     *
     * @param  string  $abstract
     * @return void
     */
    protected function dropStaleInstances($abstract)
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * Remove a resolved instance from the instance cache.
     *
     * @param  string  $abstract
     * @return void
     */
    public function forgetInstance($abstract)
    {
        unset($this->instances[$abstract]);
    }

    /**
     * Clear all of the instances from the container.
     *
     * @return void
     */
    public function forgetInstances()
    {
        $this->instances = [];
    }

    /**
     * Flush the container of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush()
    {
        $this->aliases = [];
        $this->resolved = [];
        $this->bindings = [];
        $this->instances = [];
        $this->abstractAliases = [];
    }

    /**
     * Set the globally available instance of the container.
     *
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    /**
     * Set the shared instance of the container.
     *
     * @param  \Illuminate\Contracts\Container\Container|null  $container
     * @return static
     */
    public static function setInstance(Container $container = null)
    {
        return static::$instance = $container;
    }

    /**
     * Determine if a given offset exists.
     *
     * @param  string  $key
     * @return bool
     */
    public function offsetExists($key)
    {
        return $this->bound($key);
    }

    /**
     * Get the value at a given offset.
     *
     * @param  string  $key
     * @return mixed
     */
    public function offsetGet($key)
    {
        return $this->make($key);
    }

    /**
     * Set the value at a given offset.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function offsetSet($key, $value)
    {
        $this->bind($key, $value instanceof Closure ? $value : function () use ($value) {
            return $value;
        });
    }

    /**
     * Unset the value at a given offset.
     *
     * @param  string  $key
     * @return void
     */
    public function offsetUnset($key)
    {
        unset($this->bindings[$key], $this->instances[$key], $this->resolved[$key]);
    }

    /**
     * Dynamically access container services.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this[$key];
    }

    /**
     * Dynamically set container services.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this[$key] = $value;
    }
}

class ContextualBindingBuilder
{
    /**
     * The underlying container instance.
     *
     * @var \Illuminate\Container\Container
     */
    protected $container;
    
    /**
     * The concrete instance.
     *
     * @var string
     */
    protected $concrete;
    
    /**
     * The abstract target.
     *
     * @var string
     */
    protected $needs;
    
    /**
     * Create a new contextual binding builder.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  string  $concrete
     * @return void
     */
    public function __construct(Container $container, $concrete)
    {
        $this->concrete = $concrete;
        $this->container = $container;
    }
    
    /**
     * Define the abstract target that depends on the context.
     *
     * @param  string  $abstract
     * @return $this
     */
    public function needs($abstract)
    {
        $this->needs = $abstract;
        
        return $this;
    }
    
    /**
     * Define the implementation for the contextual binding.
     *
     * @param  \Closure|string  $implementation
     * @return void
     */
    public function give($implementation)
    {
        $this->container->addContextualBinding(
            $this->concrete, $this->needs, $implementation
            );
    }
}



class BoundMethod
{
    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     */
    public static function call($container, $callback, array $parameters = [], $defaultMethod = null)
    {
        if (static::isCallableWithAtSign($callback) || $defaultMethod) {
            return static::callClass($container, $callback, $parameters, $defaultMethod);
        }
        
        return static::callBoundMethod($container, $callback, function () use ($container, $callback, $parameters) {
            return call_user_func_array(
                $callback, static::getMethodDependencies($container, $callback, $parameters)
                );
        });
    }
    
    /**
     * Call a string reference to a class using Class@method syntax.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  string  $target
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected static function callClass($container, $target, array $parameters = [], $defaultMethod = null)
    {
        $segments = explode('@', $target);
        
        // We will assume an @ sign is used to delimit the class name from the method
        // name. We will split on this @ sign and then build a callable array that
        // we can pass right back into the "call" method for dependency binding.
        $method = count($segments) == 2
        ? $segments[1] : $defaultMethod;
        
        if (is_null($method)) {
            throw new InvalidArgumentException('Method not provided.');
        }
        
        return static::call(
            $container, [$container->make($segments[0]), $method], $parameters
            );
    }
    
    /**
     * Call a method that has been bound to the container.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  callable  $callback
     * @param  mixed  $default
     * @return mixed
     */
    protected static function callBoundMethod($container, $callback, $default)
    {
        if (! is_array($callback)) {
            return $default instanceof Closure ? $default() : $default;
        }
        
        // Here we need to turn the array callable into a Class@method string we can use to
        // examine the container and see if there are any method bindings for this given
        // method. If there are, we can call this method binding callback immediately.
        $method = static::normalizeMethod($callback);
        
        if ($container->hasMethodBinding($method)) {
            return $container->callMethodBinding($method, $callback[0]);
        }
        
        return $default instanceof Closure ? $default() : $default;
    }
    
    /**
     * Normalize the given callback into a Class@method string.
     *
     * @param  callable  $callback
     * @return string
     */
    protected static function normalizeMethod($callback)
    {
        $class = is_string($callback[0]) ? $callback[0] : get_class($callback[0]);
        
        return "{$class}@{$callback[1]}";
    }
    
    /**
     * Get all dependencies for a given method.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @return array
     */
    protected static function getMethodDependencies($container, $callback, array $parameters = [])
    {
        $dependencies = [];
        
        foreach (static::getCallReflector($callback)->getParameters() as $parameter) {
            static::addDependencyForCallParameter($container, $parameter, $parameters, $dependencies);
        }
        
        return array_merge($dependencies, $parameters);
    }
    
    /**
     * Get the proper reflection instance for the given callback.
     *
     * @param  callable|string  $callback
     * @return \ReflectionFunctionAbstract
     */
    protected static function getCallReflector($callback)
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }
        
        return is_array($callback)
        ? new ReflectionMethod($callback[0], $callback[1])
        : new ReflectionFunction($callback);
    }
    
    /**
     * Get the dependency for the given call parameter.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  \ReflectionParameter  $parameter
     * @param  array  $parameters
     * @param  array  $dependencies
     * @return mixed
     */
    protected static function addDependencyForCallParameter($container, $parameter,
        array &$parameters, &$dependencies)
    {
        if (array_key_exists($parameter->name, $parameters)) {
            $dependencies[] = $parameters[$parameter->name];
            
            unset($parameters[$parameter->name]);
        } elseif ($parameter->getClass()) {
            $dependencies[] = $container->make($parameter->getClass()->name);
        } elseif ($parameter->isDefaultValueAvailable()) {
            $dependencies[] = $parameter->getDefaultValue();
        }
    }
    
    /**
     * Determine if the given string is in Class@method syntax.
     *
     * @param  mixed  $callback
     * @return bool
     */
    protected static function isCallableWithAtSign($callback)
    {
        return is_string($callback) && strpos($callback, '@') !== false;
    }
}


