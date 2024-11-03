<?php

declare(strict_types=1);

namespace Laminas\ConfigAggregator;

use Brick\VarExporter\ExportException;
use Brick\VarExporter\VarExporter;
use Closure;
use Generator;
use Laminas\Stdlib\ArrayUtils\MergeRemoveKey;
use Laminas\Stdlib\ArrayUtils\MergeReplaceKeyInterface;
use Webimpress\SafeWriter\Exception\ExceptionInterface as FileWriterException;
use Webimpress\SafeWriter\FileWriter;

use function array_key_exists;
use function class_exists;
use function date;
use function file_exists;
use function gettype;
use function is_array;
use function is_callable;
use function is_int;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Aggregate configuration generated by configuration providers.
 *
 * @psalm-type ProviderCallable = callable(): mixed
 * @psalm-type ProviderIterable = iterable<int, ProviderCallable|class-string>
 * @psalm-type PostProcessorCallable = callable(array): array
 * @psalm-type PreProcessorCallable = callable(ProviderIterable): ProviderIterable
 */
class ConfigAggregator
{
    public const ENABLE_CACHE = 'config_cache_enabled';

    public const CACHE_FILEMODE = 'config_cache_filemode';

    /**
     * @todo Make this constant private in version 2.0.0
     */
    public const CACHE_TEMPLATE = <<<'EOT'
<?php

/**
 * This configuration cache file was generated by %s
 * at %s
 */
%s

EOT;

    private array $config;

    /**
     * @param ProviderIterable $providers Array or \Iterator of providers. These may be
     *     callables, or string values representing classes that act as providers. If the latter, they must be
     *     instantiable without constructor arguments.
     * @param null|non-empty-string $cachedConfigFile Configuration cache file; config is loaded from this file if
     *     present, and written to it if not. null disables caching.
     * @param list<PostProcessorCallable|class-string> $postProcessors Array of post-processors. These may be callables,
     *     or string values representing classes that act as post-processors. If the latter, they must be instantiable
     *     without constructor arguments.
     * @param list<PreProcessorCallable|class-string> $preProcessors Array of pre-processors. These may be callables, or
     *     string values representing classes that act as pre-processors. If the latter, they must be instantiable
     *     without constructor arguments.
     */
    public function __construct(
        iterable $providers = [],
        $cachedConfigFile = null,
        array $postProcessors = [],
        array $preProcessors = [],
    ) {
        if ($this->loadConfigFromCache($cachedConfigFile)) {
            return;
        }

        $providers    = $this->preProcessProviders($preProcessors, $providers);
        $this->config = $this->loadConfigFromProviders($providers);
        $this->config = $this->postProcessConfig($postProcessors, $this->config);
        $this->cacheConfig($this->config, $cachedConfigFile);
    }

    /**
     * @return array
     */
    public function getMergedConfig()
    {
        return $this->config;
    }

    /**
     * Resolve a provider.
     *
     * If the provider is a string class name, instantiates that class and
     * tests if it is callable, returning it if true.
     *
     * If the provider is a callable, returns it verbatim.
     *
     * Raises an exception for any other condition.
     *
     * @param ProviderCallable|class-string $provider
     * @return ProviderCallable
     * @throws InvalidConfigProviderException
     */
    private function resolveProvider(string|callable $provider): callable
    {
        if (is_string($provider)) {
            if (! class_exists($provider)) {
                throw InvalidConfigProviderException::fromNamedProvider($provider);
            }
            $provider = new $provider();
        }

        if (! is_callable($provider)) {
            $type = $this->detectVariableType($provider);
            throw InvalidConfigProviderException::fromUnsupportedType($type);
        }

        // phpcs:ignore SlevomatCodingStandard.Commenting.InlineDocCommentDeclaration.MissingVariable
        /** @var ProviderCallable $provider */
        return $provider;
    }

    /**
     * Resolve a processor.
     *
     * If the processor is a string class name, instantiates that class and
     * tests if it is callable, returning it if true.
     *
     * If the processor is a callable, returns it verbatim.
     *
     * Raises an exception for any other condition.
     *
     * @param PostProcessorCallable|PreProcessorCallable|class-string $processor
     * @return PostProcessorCallable|PreProcessorCallable
     * @throws InvalidConfigProcessorException
     */
    private function resolveProcessor(string|callable $processor): callable
    {
        if (is_string($processor)) {
            if (! class_exists($processor)) {
                throw InvalidConfigProcessorException::fromNamedProcessor($processor);
            }
            $processor = new $processor();
        }

        if (! is_callable($processor)) {
            $type = $this->detectVariableType($processor);
            throw InvalidConfigProcessorException::fromUnsupportedType($type);
        }

        // phpcs:ignore SlevomatCodingStandard.Commenting.InlineDocCommentDeclaration.MissingVariable
        /** @var PostProcessorCallable|PreProcessorCallable $processor */
        return $processor;
    }

    /**
     * Perform a recursive merge of two multidimensional arrays.
     *
     * @codingStandardsIgnoreStart
     * Copied from https://github.com/laminas/laminas-stdlib/blob/980ce463c29c1a66c33e0eb67961bba895d0e19e/src/ArrayUtils.php#L269
     * @codingStandardsIgnoreEnd
     *
     * @return $a
     */
    private function mergeArray(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if ($value instanceof MergeReplaceKeyInterface) {
                $a[$key] = $value->getData();
            } elseif (isset($a[$key]) || array_key_exists($key, $a)) {
                if ($value instanceof MergeRemoveKey) {
                    unset($a[$key]);
                } elseif (is_int($key)) {
                    $a[] = $value;
                } elseif (is_array($value) && is_array($a[$key])) {
                    $a[$key] = $this->mergeArray($a[$key], $value);
                } else {
                    $a[$key] = $value;
                }
            } else {
                if (! $value instanceof MergeRemoveKey) {
                    $a[$key] = $value;
                }
            }
        }
        return $a;
    }

    /**
     * Merge configuration from a provider with existing configuration.
     *
     * @param array $mergedConfig Passed by reference as a performance/resource
     *     optimization.
     * @param mixed $config Configuration generated by the $provider.
     * @param callable $provider Provider responsible for generating $config;
     *     used for exception messages only.
     * @throws InvalidConfigProviderException
     */
    private function mergeConfig(array &$mergedConfig, mixed $config, callable $provider): void
    {
        if (! is_array($config)) {
            $type = $this->detectVariableType($provider);

            throw new InvalidConfigProviderException(sprintf(
                'Cannot read config from %s; does not return array',
                $type
            ));
        }

        $mergedConfig = $this->mergeArray($mergedConfig, $config);
    }

    /**
     * Iterate providers, merging config from each with the previous.
     *
     * @param ProviderIterable $providers
     */
    private function loadConfigFromProviders(iterable $providers): array
    {
        $mergedConfig = [];
        foreach ($providers as $provider) {
            $provider = $this->resolveProvider($provider);
            $config   = $provider();
            if (! $config instanceof Generator) {
                $this->mergeConfig($mergedConfig, $config, $provider);
                continue;
            }

            // Handle generators
            foreach ($config as $cfg) {
                $this->mergeConfig($mergedConfig, $cfg, $provider);
            }
        }
        return $mergedConfig;
    }

    /**
     * Attempt to load the configuration from a cache file.
     *
     * @param null|non-empty-string $cachedConfigFile
     */
    private function loadConfigFromCache(null|string $cachedConfigFile): bool
    {
        if (null === $cachedConfigFile) {
            return false;
        }

        if (! file_exists($cachedConfigFile)) {
            return false;
        }

        $this->config = require $cachedConfigFile;
        return true;
    }

    /**
     * Attempt to cache discovered configuration.
     *
     * @param null|non-empty-string $cachedConfigFile
     * @throws ConfigCannotBeCachedException
     */
    private function cacheConfig(array $config, null|string $cachedConfigFile): void
    {
        if (null === $cachedConfigFile) {
            return;
        }

        if (empty($config[static::ENABLE_CACHE])) {
            return;
        }

        try {
            $contents = sprintf(
                self::CACHE_TEMPLATE,
                static::class,
                date('c'),
                VarExporter::export($config, VarExporter::ADD_RETURN | VarExporter::CLOSURE_SNAPSHOT_USES)
            );
        } catch (ExportException $e) {
            throw ConfigCannotBeCachedException::fromExporterException($e);
        }

        $mode = $config[self::CACHE_FILEMODE] ?? null;
        $this->writeCache($cachedConfigFile, $contents, $mode);
    }

    /**
     * @param list<PreProcessorCallable|class-string> $processors
     * @param ProviderIterable $providers
     * @return ProviderIterable
     */
    private function preProcessProviders(array $processors, iterable $providers): iterable
    {
        foreach ($processors as $processor) {
            /** @var PreProcessorCallable $processorCallable */
            $processorCallable = $this->resolveProcessor($processor);
            $providers         = $processorCallable($providers);
        }

        return $providers;
    }

    /**
     * @param list<PostProcessorCallable|class-string> $processors
     */
    private function postProcessConfig(array $processors, array $config): array
    {
        foreach ($processors as $processor) {
            /** @var PostProcessorCallable $processorCallable */
            $processorCallable = $this->resolveProcessor($processor);
            $config            = $processorCallable($config);
        }

        return $config;
    }

    private function detectVariableType(object|callable $variable): string
    {
        if ($variable instanceof Closure) {
            return 'Closure';
        }

        if (is_object($variable)) {
            return $variable::class;
        }

        return is_string($variable) ? $variable : gettype($variable);
    }

    /**
     * Attempt to cache discovered configuration.
     *
     * @param non-empty-string $cachedConfigFile
     */
    private function writeCache(string $cachedConfigFile, string $contents, int|null $mode): void
    {
        try {
            if ($mode !== null) {
                FileWriter::writeFile($cachedConfigFile, $contents, $mode);
            } else {
                FileWriter::writeFile($cachedConfigFile, $contents);
            }
        } catch (FileWriterException) {
            // ignore errors writing cache file
        }
    }
}
