<?php

declare(strict_types=1);

/*
 * Writes the Symfony service map (var/cache/igor_service_map.json) that Igor-PHP
 * expects in its Deep Audit mode (Level 3 - Igor Agent).
 *
 * It does what IgorPhpBundle does (IgorAliasSnapshotPass before private aliases
 * are removed + IgorDiscoveryPass after unused definitions are removed), but
 * against an in-memory ContainerBuilder - so nothing is added to composer.json
 * and the on-disk container is left alone. That matters here: the FrankenPHP
 * worker in this job is serving requests out of that very cache directory.
 *
 * Without this file `igor-php --no-agent` falls back to `debug:container
 * --show-hidden`, which on Symfony 7.4/8.x returns ONLY hidden services (the
 * ones whose id starts with a dot) and therefore misses ~99% of the container.
 * The audit then looks clean because it saw almost nothing.
 */

use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\Kernel;

$appDir = $argv[1] ?? '/app';
$env = $argv[2] ?? ($_SERVER['APP_ENV'] ?? 'prod');

if (!is_file($appDir . '/vendor/autoload.php')) {
    fwrite(\STDERR, "ERROR: no vendor/autoload.php in {$appDir} - the application has to be built first\n");
    exit(1);
}

require $appDir . '/vendor/autoload.php';

if (class_exists(Dotenv::class) && is_file($appDir . '/.env')) {
    (new Dotenv())->bootEnv($appDir . '/.env');
}

$aliasSnapshot = new class() implements CompilerPassInterface {
    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, string> */
    private array $targetClasses = [];

    public function process(ContainerBuilder $container): void
    {
        $this->aliases = [];
        $this->targetClasses = [];

        foreach ($container->getAliases() as $id => $alias) {
            $this->aliases[$id] = (string) $alias;

            try {
                $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass());
                if (\is_string($class) && '' !== $class) {
                    $this->targetClasses[$id] = $class;
                }
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param list<string> $liveClasses
     *
     * @return array<string, string>
     */
    public function getAliases(ContainerBuilder $container, array $liveClasses): array
    {
        $live = array_flip($liveClasses);
        $aliases = [];

        foreach ($this->aliases as $id => $target) {
            if (!$container->hasDefinition($target) && !$container->hasAlias($target)) {
                if (!isset($this->targetClasses[$id], $live[$this->targetClasses[$id]])) {
                    continue;
                }
                $target = $this->targetClasses[$id];
            }
            $aliases[$id] = $target;
        }

        return $aliases;
    }
};

$discovery = new class($aliasSnapshot, $appDir, $env) implements CompilerPassInterface {
    private const SCALAR_TYPES = [
        'int', 'float', 'string', 'bool', 'array', 'object',
        'iterable', 'mixed', 'void', 'null', 'false', 'true',
    ];

    public function __construct(
        private object $aliasSnapshot,
        private string $appDir,
        private string $env,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $serviceMap = [
            'definitions' => [],
            'aliases' => [],
        ];

        foreach ($container->getDefinitions() as $id => $definition) {
            $this->registerDefinition($id, $definition, $container, $serviceMap, $definition->isShared());
            $this->collectInlinedDefinitions($definition, $container, $serviceMap, $definition->isShared());
        }

        $liveClasses = array_column($serviceMap['definitions'], 'class');
        $serviceMap['aliases'] = $this->aliasSnapshot->getAliases($container, $liveClasses);

        foreach ($container->getAliases() as $id => $alias) {
            $serviceMap['aliases'][$id] = (string) $alias;
        }

        $json = json_encode($serviceMap, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        foreach ([
            $this->appDir . '/var/cache/' . $this->env . '/igor_service_map.json',
            $this->appDir . '/var/cache/igor_service_map.json',
        ] as $target) {
            $dir = \dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($target, $json);
        }
    }

    /** @param array<string, mixed> $serviceMap */
    private function registerDefinition(
        string $id,
        Definition $definition,
        ContainerBuilder $container,
        array &$serviceMap,
        bool $isShared,
    ): void {
        if (isset($serviceMap['definitions'][$id])) {
            return;
        }

        if ($definition->isSynthetic() || !$definition->getClass() || $definition->hasTag('container.excluded')) {
            return;
        }

        $class = $container->getParameterBag()->resolveValue($definition->getClass());
        if (!$class || !\is_string($class)) {
            return;
        }

        if (\in_array(strtolower($class), self::SCALAR_TYPES, true)) {
            return;
        }

        // ONLY the `kernel.reset` tag on the compiled definition counts.
        // The original IgorDiscoveryPass in IgorPhpBundle also falls back to
        // `is_subclass_of($class, ResetInterface::class)`, i.e. it treats
        // implementing the interface as proof that the service is reset. It is
        // not: a bundle service is not autoconfigured, so it can implement
        // ResetInterface and still never be tagged, hence never reset. That
        // fallback is what hides decorators such as ShopBasedCartContext
        // (`sylius.context.cart.new_shop_based`) - a real carry-over this suite
        // measures in Behat.
        $isResettable = $definition->hasTag('kernel.reset');

        $serviceMap['definitions'][$id] = [
            'class' => $class,
            'public' => $definition->isPublic(),
            'shared' => $isShared,
            'resettable' => $isResettable,
            'arguments' => $this->extractDependencies($definition, $container),
        ];
    }

    /** @param array<string, mixed> $serviceMap */
    private function collectInlinedDefinitions(
        Definition $definition,
        ContainerBuilder $container,
        array &$serviceMap,
        bool $parentShared,
    ): void {
        $values = [$definition->getArguments()];

        foreach ($definition->getMethodCalls() as $call) {
            if (isset($call[1]) && \is_array($call[1])) {
                $values[] = $call[1];
            }
        }

        $values[] = $definition->getProperties();

        if (\is_array($definition->getConfigurator())) {
            $values[] = $definition->getConfigurator();
        }

        if (\is_array($definition->getFactory())) {
            $values[] = $definition->getFactory();
        }

        $this->extractDefinitionsRecursively($values, $container, $serviceMap, $parentShared);
    }

    /** @param array<string, mixed> $serviceMap */
    private function extractDefinitionsRecursively(
        mixed $data,
        ContainerBuilder $container,
        array &$serviceMap,
        bool $parentShared,
    ): void {
        if ($data instanceof Definition) {
            $rawClass = $data->getClass();
            if ($rawClass) {
                $resolvedClass = $container->getParameterBag()->resolveValue($rawClass);
                if (\is_string($resolvedClass) && '' !== $resolvedClass) {
                    $inlinedId = 'inlined.' . $resolvedClass . '.' . spl_object_id($data);
                    $isShared = $parentShared ? true : $data->isShared();
                    $this->registerDefinition($inlinedId, $data, $container, $serviceMap, $isShared);
                    $this->collectInlinedDefinitions($data, $container, $serviceMap, $isShared);
                }
            }

            return;
        }

        if (\is_object($data)) {
            $isServiceClosure = ($data instanceof ServiceClosureArgument)
                || str_ends_with($data::class, 'ServiceClosureArgument');
            $childParentShared = $isServiceClosure ? false : $parentShared;

            if (method_exists($data, 'getValues')) {
                $this->extractDefinitionsRecursively($data->getValues(), $container, $serviceMap, $childParentShared);

                return;
            }
            if (method_exists($data, 'getValue')) {
                $this->extractDefinitionsRecursively($data->getValue(), $container, $serviceMap, $childParentShared);

                return;
            }
        }

        if (\is_array($data) || $data instanceof \Traversable) {
            foreach ($data as $item) {
                $this->extractDefinitionsRecursively($item, $container, $serviceMap, $parentShared);
            }
        }
    }

    /** @return list<array{type: string, id: string}> */
    private function extractDependencies(Definition $definition, ContainerBuilder $container): array
    {
        $deps = [];

        $extract = static function (mixed $val) use (&$deps, &$extract, $container): void {
            if ($val instanceof Reference) {
                $deps[] = ['type' => 'service', 'id' => (string) $val];
            } elseif ($val instanceof Definition && $val->getClass()) {
                $resolvedClass = $container->getParameterBag()->resolveValue($val->getClass());
                if (\is_string($resolvedClass) && '' !== $resolvedClass) {
                    $deps[] = ['type' => 'service', 'id' => 'inlined.' . $resolvedClass . '.' . spl_object_id($val)];
                }
            } elseif (\is_object($val) && method_exists($val, 'getValues')) {
                $extract($val->getValues());
            } elseif (\is_object($val) && method_exists($val, 'getValue')) {
                $extract($val->getValue());
            } elseif (\is_array($val) || $val instanceof \Traversable) {
                foreach ($val as $subVal) {
                    $extract($subVal);
                }
            }
        };

        foreach ($definition->getArguments() as $arg) {
            $extract($arg);
        }

        foreach ($definition->getMethodCalls() as $call) {
            if (isset($call[1]) && \is_array($call[1])) {
                foreach ($call[1] as $arg) {
                    $extract($arg);
                }
            }
        }

        return $deps;
    }
};

$kernel = new \App\Kernel($env, false);
(new \ReflectionMethod(Kernel::class, 'initializeBundles'))->invoke($kernel);
/** @var ContainerBuilder $container */
$container = (new \ReflectionMethod(Kernel::class, 'getContainerBuilder'))->invoke($kernel);
(new \ReflectionMethod(Kernel::class, 'prepareContainer'))->invoke($kernel, $container);

$container->addCompilerPass($aliasSnapshot, PassConfig::TYPE_BEFORE_REMOVING, -1000);
$container->addCompilerPass($discovery, PassConfig::TYPE_AFTER_REMOVING, -1000);

$loader = (new \ReflectionMethod(Kernel::class, 'getContainerLoader'))->invoke($kernel, $container);
$kernel->registerContainerConfiguration($loader);
$container->compile();
