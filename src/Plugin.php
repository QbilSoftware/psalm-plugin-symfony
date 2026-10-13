<?php

namespace Psalm\SymfonyPsalmPlugin;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Psalm\Config;
use Psalm\Exception\ConfigException;
use Psalm\Internal\CliUtils;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\SymfonyPsalmPlugin\Handler\AnnotationHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ConsoleHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ContainerDependencyHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ContainerHandler;
use Psalm\SymfonyPsalmPlugin\Handler\DoctrineQueryBuilderHandler;
use Psalm\SymfonyPsalmPlugin\Handler\DoctrineRepositoryHandler;
use Psalm\SymfonyPsalmPlugin\Handler\HeaderBagHandler;
use Psalm\SymfonyPsalmPlugin\Handler\ParameterBagHandler;
use Psalm\SymfonyPsalmPlugin\Handler\RequiredSetterHandler;
use Psalm\SymfonyPsalmPlugin\Provider\FormGetErrorsReturnTypeProvider;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\SymfonyPsalmPlugin\Symfony\InstalledSymfonyVersion;
use Psalm\SymfonyPsalmPlugin\Taint\TaintSourcesAndSinks;
use Psalm\SymfonyPsalmPlugin\Twig\AnalyzedTemplatesTainter;
use Psalm\SymfonyPsalmPlugin\Twig\CachedTemplatesMapping;
use Psalm\SymfonyPsalmPlugin\Twig\CachedTemplatesTainter;
use Psalm\SymfonyPsalmPlugin\Twig\TemplateFileAnalyzer;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpKernel\Kernel;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Extension\ExtensionInterface;

/**
 * @psalm-suppress UnusedClass
 */
final class Plugin implements PluginEntryPointInterface
{
    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        require_once __DIR__.'/Handler/HeaderBagHandler.php';
        require_once __DIR__.'/Handler/ContainerHandler.php';
        require_once __DIR__.'/Handler/ConsoleHandler.php';
        require_once __DIR__.'/Handler/ContainerDependencyHandler.php';
        require_once __DIR__.'/Handler/RequiredSetterHandler.php';
        require_once __DIR__.'/Provider/FormGetErrorsReturnTypeProvider.php';
        require_once __DIR__.'/Taint/TaintSourcesAndSinks.php';

        $registration->registerHooksFromClass(HeaderBagHandler::class);
        $registration->registerHooksFromClass(ConsoleHandler::class);
        $registration->registerHooksFromClass(ContainerDependencyHandler::class);
        $registration->registerHooksFromClass(RequiredSetterHandler::class);
        $registration->registerHooksFromClass(TaintSourcesAndSinks::class);

        if (class_exists(\Doctrine\ORM\QueryBuilder::class)) {
            require_once __DIR__.'/Handler/DoctrineQueryBuilderHandler.php';
            $registration->registerHooksFromClass(DoctrineQueryBuilderHandler::class);

            require_once __DIR__.'/Handler/DoctrineRepositoryHandler.php';
            $registration->registerHooksFromClass(DoctrineRepositoryHandler::class);
        }

        if (class_exists(AnnotationRegistry::class)) {
            if (method_exists(AnnotationRegistry::class, 'registerLoader')) {
                AnnotationRegistry::registerLoader('class_exists');
            }

            require_once __DIR__.'/Handler/AnnotationHandler.php';
            $registration->registerHooksFromClass(AnnotationHandler::class);
        }

        $twigExtensions = [];

        if (isset($config->containerXml)) {
            $containerMeta = new ContainerMeta((array) $config->containerXml);
            ContainerHandler::init($containerMeta);

            try {
                foreach ($containerMeta->get('twig')->getMethodCalls() as [$method, $arguments]) {
                    if ('addExtension' !== $method) {
                        continue;
                    }

                    try {
                        $definition = $containerMeta->get((string) $arguments[0]);
                    } catch (ServiceNotFoundException $e) {
                        continue;
                    }

                    if (AttributeExtension::class !== $definition->getClass()) {
                        $twigExtensions[] = $definition->getClass();
                        continue;
                    }

                    // the extension of a class of #[AsTwigFilter] and the like methods, named by its argument
                    /** @var class-string $attributeClass */
                    $attributeClass = (string) $definition->getArgument(0);
                    $twigExtensions[] = new AttributeExtension($attributeClass);
                }
            } catch (ServiceNotFoundException $e) {
            }

            require_once __DIR__.'/Handler/ParameterBagHandler.php';
            ParameterBagHandler::init($containerMeta);
            $registration->registerHooksFromClass(ParameterBagHandler::class);
        }

        if (isset($config->twigEnvironment)) {
            $twigExtensions = [...$twigExtensions, ...self::extensionsOf((string) $config->twigEnvironment)];
        }

        /** @var list<class-string|ExtensionInterface> $twigExtensions */
        TemplateFileAnalyzer::initExtensions($twigExtensions);

        $registration->registerHooksFromClass(ContainerHandler::class);

        $symfonyMajorVersion = (string) ($config->symfonyMajorVersion
            ?? InstalledSymfonyVersion::major(self::projectVendorDirectory())
            ?? Kernel::MAJOR_VERSION);

        $this->addStubs($registration, __DIR__.'/Stubs/common');
        $this->addStubs($registration, __DIR__.'/Stubs/'.$symfonyMajorVersion);
        $this->addStubs($registration, __DIR__.'/Stubs/php');

        if (isset($config->twigCachePath)) {
            $twig_cache_path = getcwd().DIRECTORY_SEPARATOR.ltrim((string) $config->twigCachePath, DIRECTORY_SEPARATOR);
            if (!is_dir($twig_cache_path) || !is_readable($twig_cache_path)) {
                throw new ConfigException(sprintf('The twig cache directory %s is missing or not readable.', $twig_cache_path));
            }

            require_once __DIR__.'/Twig/CachedTemplatesTainter.php';
            $registration->registerHooksFromClass(CachedTemplatesTainter::class);

            require_once __DIR__.'/Twig/CachedTemplatesMapping.php';
            $registration->registerHooksFromClass(CachedTemplatesMapping::class);
            CachedTemplatesMapping::setCachePath($twig_cache_path);
        }

        require_once __DIR__.'/Twig/AnalyzedTemplatesTainter.php';
        $registration->registerHooksFromClass(AnalyzedTemplatesTainter::class);

        if (isset($config->twigRootPath)) {
            $twig_root_path = trim((string) $config->twigRootPath, DIRECTORY_SEPARATOR);
            $real_path = getcwd().DIRECTORY_SEPARATOR.$twig_root_path;
            if (!is_dir($real_path) || !is_readable($real_path)) {
                throw new ConfigException(sprintf('The twig templates root directory %s is missing or not readable.', $twig_root_path));
            }

            TemplateFileAnalyzer::setTemplateRootPath($twig_root_path);
        }

        $registration->registerHooksFromClass(FormGetErrorsReturnTypeProvider::class);
    }

    private static function projectVendorDirectory(): string
    {
        $baseDirectory = Config::getInstance()->base_dir;

        return rtrim($baseDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.CliUtils::getVendorDir($baseDirectory);
    }

    /**
     * The extensions of the Twig environment a PHP file of the application returns, built by the application
     * with their services, for an application without a container XML or with extensions that need services.
     *
     * @return list<ExtensionInterface>
     */
    private static function extensionsOf(string $environmentFile): array
    {
        $path = (string) getcwd().DIRECTORY_SEPARATOR.ltrim($environmentFile, DIRECTORY_SEPARATOR);
        if (!is_file($path)) {
            throw new ConfigException(sprintf('The Twig environment file %s is missing.', $environmentFile));
        }

        /** @psalm-suppress UnresolvableInclude the application names the file in its Psalm configuration */
        $environment = (static fn (): mixed => require $path)();
        if (!$environment instanceof Environment) {
            throw new ConfigException(sprintf('The Twig environment file %s does not return a %s.', $environmentFile, Environment::class));
        }

        return array_values($environment->getExtensions());
    }

    private function addStubs(RegistrationInterface $registration, string $path): void
    {
        if (!is_dir($path)) {
            // e.g., looking for stubs for version 3, but there aren't any at time of writing, so don't try and load them.
            return;
        }

        $a = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        foreach ($a as $file) {
            if (!$file->isDir()) {
                $registration->addStubFile($file->getPathname());
            }
        }
    }
}
