<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

use Psalm\Codebase;
use Psalm\Context as PsalmContext;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Extension\AttributeExtension;
use Twig\Extension\ExtensionInterface;
use Twig\Loader\FilesystemLoader;
use Twig\NodeTraverser;

/**
 * This class is to be used as a "checker" for the `.twig` files in the psalm configuration.
 */
final class TemplateFileAnalyzer extends FileAnalyzer
{
    private static string $rootPath = 'templates';

    /**
     * The extensions of the application, by class or as built by the application.
     *
     * @var list<class-string|ExtensionInterface>
     */
    private static array $extensions = [];

    private static ?Environment $environment = null;

    /**
     * @param list<class-string|ExtensionInterface> $extensions
     */
    public static function initExtensions(array $extensions): void
    {
        self::$extensions = $extensions;
        self::$environment = null;
    }

    #[\Override]
    public function analyze(
        ?PsalmContext $file_context = null,
        ?PsalmContext $global_context = null,
    ): void {
        $codebase = $this->project_analyzer->getCodebase();
        $taint = $codebase->taint_flow_graph;

        if (null === $taint) {
            return;
        }

        $twig = self::getEnvironment($codebase);

        $local_file_name = str_starts_with($this->file_name, self::$rootPath.'/')
            ? substr($this->file_name, \strlen(self::$rootPath) + 1)
            : $this->file_name;
        try {
            $twig_source = $twig->getLoader()->getSourceContext($local_file_name);
            $tree = $twig->parse($twig->tokenize($twig_source));
        } catch (Error $error) {
            // e.g. a filter of an extension the analysis was not given
            $this->project_analyzer->progress->warning(\sprintf('The taints of the Twig template %s are not analyzed: %s', $local_file_name, $error->getMessage()));

            return;
        }

        $twigContext = new Context($twig_source, $taint, $twig, $codebase);

        $traverser = new NodeTraverser($twig, [
            new TaintAnalysisVisitor($twigContext),
        ]);

        $traverser->traverse($tree);

        $twigContext->taintUnassignedVariables($local_file_name);
        $twigContext->taintSinks($local_file_name);
    }

    /**
     * The Twig environment the templates are analyzed with.
     */
    public static function getEnvironment(Codebase $codebase): Environment
    {
        if (null !== self::$environment) {
            return self::$environment;
        }

        // a template outside of the root is named after its path in the project
        $loader = new FilesystemLoader([self::$rootPath, '.'], $codebase->config->base_dir);
        $twig = new Environment($loader, [
            'cache' => false,
            'auto_reload' => true,
            'debug' => true,
            'optimizations' => 0,
            'strict_variables' => false,
        ]);
        foreach (self::$extensions as $extension) {
            if (\is_string($extension)) {
                if (!class_exists($extension) || !is_a($extension, ExtensionInterface::class, true)) {
                    continue;
                }

                // only the parser runs, which needs what the extension lists, not the services it would be given
                $extension = (new \ReflectionClass($extension))->newInstanceWithoutConstructor();
            }

            $name = $extension instanceof AttributeExtension ? $extension->getClass() : $extension::class;
            if ($twig->hasExtension($name)) {
                continue;
            }

            if (!self::listsItsParts($extension)) {
                $codebase->progress->warning(\sprintf('The Twig extension %s is left out of the analysis: it needs its services to list its filters, functions and tags. Give the analysis the Twig environment of the application with the twigEnvironment option.', $name));

                continue;
            }

            $twig->addExtension($extension);
        }

        return self::$environment = $twig;
    }

    /**
     * Whether the extension can list what it adds to Twig. One built without its constructor cannot when
     * it reads a service to do so, and Twig would then fail on every template, deep in the analysis.
     */
    private static function listsItsParts(ExtensionInterface $extension): bool
    {
        try {
            $extension->getTokenParsers();
            $extension->getNodeVisitors();
            $extension->getFilters();
            $extension->getTests();
            $extension->getFunctions();
            if (method_exists($extension, 'getExpressionParsers')) {
                $extension->getExpressionParsers();
            } else {
                $extension->getOperators();
            }
        } catch (\Error) {
            return false;
        }

        return true;
    }

    public static function setTemplateRootPath(string $rootPath): void
    {
        self::$rootPath = $rootPath;
        self::$environment = null;
    }
}
