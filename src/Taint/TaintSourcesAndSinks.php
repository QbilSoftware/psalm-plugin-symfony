<?php

namespace Psalm\SymfonyPsalmPlugin\Taint;

use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\TaintKind;

/**
 * Marks the Symfony methods that return user input or must not receive it.
 *
 * Done on the scanned methods rather than with stubs: a stub replaces the whole signature, the stubs
 * of this plugin depend on the Symfony major version, and a source or sink must also apply to every
 * class that overrides the method.
 */
final class TaintSourcesAndSinks implements AfterCodebasePopulatedInterface
{
    /**
     * @var list<array{class: string, method: string, params: list<int>, taint: string}>
     */
    private const SINKS = [
        // A command line runs in a shell; an array command does not
        ['class' => 'Symfony\Component\Process\Process', 'method' => 'fromShellCommandline', 'params' => [0], 'taint' => 'shell'],
        ['class' => 'Symfony\Component\Filesystem\Filesystem', 'method' => 'dumpFile', 'params' => [0], 'taint' => 'file'],
        ['class' => 'Symfony\Component\Filesystem\Filesystem', 'method' => 'appendToFile', 'params' => [0], 'taint' => 'file'],
        ['class' => 'Symfony\Component\Filesystem\Filesystem', 'method' => 'readFile', 'params' => [0], 'taint' => 'file'],
        ['class' => 'Symfony\Component\Filesystem\Filesystem', 'method' => 'remove', 'params' => [0], 'taint' => 'file'],
        ['class' => 'Symfony\Component\Filesystem\Filesystem', 'method' => 'copy', 'params' => [0, 1], 'taint' => 'file'],
        ['class' => 'Symfony\Component\Filesystem\Filesystem', 'method' => 'rename', 'params' => [0, 1], 'taint' => 'file'],
        ['class' => 'Symfony\Component\Filesystem\Filesystem', 'method' => 'symlink', 'params' => [0, 1], 'taint' => 'file'],
        // A mail client shows the HTML body as it is
        ['class' => 'Symfony\Component\Mime\Email', 'method' => 'html', 'params' => [0], 'taint' => 'html'],
        ['class' => 'Symfony\Contracts\HttpClient\HttpClientInterface', 'method' => 'request', 'params' => [1], 'taint' => 'ssrf'],
        // An open redirect: the target goes into the Location header
        ['class' => 'Symfony\Component\HttpFoundation\RedirectResponse', 'method' => '__construct', 'params' => [0], 'taint' => 'header'],
        ['class' => 'Symfony\Component\HttpFoundation\RedirectResponse', 'method' => 'setTargetUrl', 'params' => [0], 'taint' => 'header'],
        ['class' => 'Symfony\Bundle\FrameworkBundle\Controller\AbstractController', 'method' => 'redirect', 'params' => [0], 'taint' => 'header'],
        // A template made from a string runs Twig code
        ['class' => 'Twig\Environment', 'method' => 'createTemplate', 'params' => [0], 'taint' => 'eval'],
    ];

    /**
     * Methods whose return value is user input.
     *
     * @var list<array{class: string, method: string}>
     */
    private const SOURCES = [
        ['class' => 'Symfony\Component\HttpFoundation\File\UploadedFile', 'method' => 'getClientOriginalName'],
        ['class' => 'Symfony\Component\HttpFoundation\File\UploadedFile', 'method' => 'getClientOriginalExtension'],
        ['class' => 'Symfony\Component\HttpFoundation\File\UploadedFile', 'method' => 'getClientOriginalPath'],
        ['class' => 'Symfony\Component\HttpFoundation\File\UploadedFile', 'method' => 'getClientMimeType'],
        ['class' => 'Symfony\Component\HttpFoundation\Request', 'method' => 'getContent'],
        ['class' => 'Symfony\Component\HttpFoundation\Request', 'method' => 'toArray'],
        ['class' => 'Symfony\Component\HttpFoundation\Request', 'method' => 'getPayload'],
        ['class' => 'Symfony\Component\HttpFoundation\Request', 'method' => 'getRequestUri'],
        ['class' => 'Symfony\Component\HttpFoundation\Request', 'method' => 'getPathInfo'],
        ['class' => 'Symfony\Component\HttpFoundation\Request', 'method' => 'getQueryString'],
        ['class' => 'Symfony\Component\HttpFoundation\Request', 'method' => 'getUri'],
        ['class' => 'Symfony\Component\Form\FormInterface', 'method' => 'getData'],
        ['class' => 'Symfony\Component\Form\FormInterface', 'method' => 'getViewData'],
        ['class' => 'Symfony\Component\Form\FormInterface', 'method' => 'getNormData'],
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        foreach (ClassLikeStorageProvider::getAll() as $storage) {
            $lineage = self::lineage($storage);

            foreach (self::SINKS as $sink) {
                $method = $storage->methods[strtolower($sink['method'])] ?? null;
                if (null === $method || !in_array(strtolower($sink['class']), $lineage, true)) {
                    continue;
                }

                foreach ($sink['params'] as $position) {
                    if (isset($method->params[$position])) {
                        $method->params[$position]->sinks |= TaintKind::TAINT_NAMES[$sink['taint']];
                    }
                }
            }

            foreach (self::SOURCES as $source) {
                $method = $storage->methods[strtolower($source['method'])] ?? null;
                if (null !== $method && in_array(strtolower($source['class']), $lineage, true)) {
                    $method->taint_source_types |= TaintKind::ALL_INPUT;
                }
            }
        }
    }

    /**
     * @return list<lowercase-string>
     *
     * @psalm-capabilities read-props
     */
    private static function lineage(ClassLikeStorage $storage): array
    {
        return [
            strtolower($storage->name),
            ...array_keys($storage->parent_classes),
            ...array_keys($storage->class_implements),
            ...array_keys($storage->parent_interfaces),
        ];
    }
}
