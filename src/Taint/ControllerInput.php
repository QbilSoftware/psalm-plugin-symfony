<?php

namespace Psalm\SymfonyPsalmPlugin\Taint;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\MethodAnalyzer;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Plugin\EventHandler\AddTaintsInterface;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\StatementsSource;
use Psalm\Storage\AttributeStorage;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TIterable;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Marks the input Symfony maps from the request to controller arguments as tainted.
 *
 * Two kinds: the scalar and array arguments of a route action (a placeholder, a #[MapQueryParameter]
 * or a mapped array), and every property read of a class Symfony fills from the request (a
 * #[MapRequestPayload] or #[MapQueryString] argument, the data_class of a form). Psalm cannot see the
 * serializer or the property accessor write those properties, so the read is the only place left to
 * mark, wherever the object travels after the controller.
 *
 * Int, float and bool values are left out: they cannot carry markup, SQL or a path.
 */
final class ControllerInput implements AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface, AddTaintsInterface
{
    private const ROUTE_ATTRIBUTES = [
        'symfony\component\routing\attribute\route',
        'symfony\component\routing\annotation\route',
    ];

    /**
     * Attributes of the arguments Symfony maps from the request.
     */
    private const REQUEST_ARGUMENT_ATTRIBUTES = [
        'symfony\component\httpkernel\attribute\maprequestpayload',
        'symfony\component\httpkernel\attribute\mapquerystring',
        'symfony\component\httpkernel\attribute\mapqueryparameter',
    ];

    /**
     * Attributes of the arguments Symfony builds as an object from the request.
     */
    private const REQUEST_OBJECT_ATTRIBUTES = [
        'symfony\component\httpkernel\attribute\maprequestpayload',
        'symfony\component\httpkernel\attribute\mapquerystring',
    ];

    private const DATA_CLASSES = 'symfony_form_data_classes';

    private const ENTITY_ATTRIBUTES = [
        'doctrine\orm\mapping\entity',
        'doctrine\orm\mapping\embeddable',
        'doctrine\orm\mapping\mappedsuperclass',
    ];

    /**
     * @var array<string, true>
     */
    private static array $inputClasses = [];

    /**
     * @var array<string, list<string>>
     */
    private static array $taintedParameters = [];

    /**
     * The expressions already tainted, with the context they were tainted in. Psalm asks again, in the
     * same context, for an expression given as an argument, and a second taint is a duplicate report.
     *
     * @var \WeakMap<object, mixed>|null
     */
    private static ?\WeakMap $taintedExpressions = null;

    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $aliases = $event->getStatementsSource()->getAliases();
        $classes = [];

        foreach ((new NodeFinder())->find($event->getStmt()->stmts, self::setsDataClass(...)) as $node) {
            $value = $node instanceof MethodCall ? $node->getArgs()[1]->value : ($node instanceof ArrayItem ? $node->value : null);
            if ($value instanceof ClassConstFetch && $value->class instanceof Name) {
                $classes[] = strtolower(ClassLikeAnalyzer::getFQCLNFromNameObject($value->class, $aliases));
            }
        }

        // Kept in the storage rather than in this class: Psalm scans in other processes and caches the storage
        if ([] !== $classes) {
            $event->getStorage()->custom_metadata[self::DATA_CLASSES] = $classes;
        }
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $storages = ClassLikeStorageProvider::getAll();

        foreach ($storages as $storage) {
            $classes = self::requestObjectClasses($storage);
            if (isset($storage->class_implements['symfony\component\form\formtypeinterface'])) {
                /** @var list<lowercase-string> $dataClasses */
                $dataClasses = $storage->custom_metadata[self::DATA_CLASSES] ?? [];
                $classes = [...$classes, ...$dataClasses];
            }

            foreach ($classes as $class) {
                self::addInputClass($storages, $class, self::namespaceOf($class));
            }
        }
    }

    #[\Override]
    public static function addTaints(AddRemoveTaintsEvent $event): int
    {
        $expr = $event->getExpr();
        self::$taintedExpressions ??= new \WeakMap();
        if ($event->getContext() === (self::$taintedExpressions[$expr] ?? null)) {
            return 0;
        }

        $taints = self::taintsOf($expr, $event);
        if (0 !== $taints) {
            self::$taintedExpressions[$expr] = $event->getContext();
        }

        return $taints;
    }

    private static function taintsOf(Node $expr, AddRemoveTaintsEvent $event): int
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            return in_array($expr->name, self::taintedParameters($event->getStatementsSource(), $event->getCodebase()), true)
                ? TaintKind::ALL_INPUT
                : 0;
        }

        if (($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) && $expr->name instanceof Identifier) {
            $objectType = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->var);
            $inputClass = null !== $objectType ? self::inputClassOf($objectType, $event->getCodebase()) : null;

            return null !== $inputClass && !self::isNumberOrBool(self::propertyType($inputClass, $expr->name->name, $event->getCodebase()))
                ? TaintKind::ALL_INPUT
                : 0;
        }

        return 0;
    }

    /**
     * `'data_class' => Name::class` in an array, or `->setDefault('data_class', Name::class)`.
     */
    private static function setsDataClass(Node $node): bool
    {
        if ($node instanceof ArrayItem) {
            return $node->key instanceof String_ && 'data_class' === $node->key->value;
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier && 'setdefault' === $node->name->toLowerString()) {
            $args = $node->getArgs();

            return isset($args[1]) && $args[0]->value instanceof String_ && 'data_class' === $args[0]->value->value;
        }

        return false;
    }

    /**
     * @return list<lowercase-string>
     *
     * @psalm-capabilities read-props
     */
    private static function requestObjectClasses(ClassLikeStorage $storage): array
    {
        $classes = [];

        foreach ($storage->methods as $method) {
            foreach ($method->params as $param) {
                if (self::hasAttribute($param->attributes, self::REQUEST_OBJECT_ATTRIBUTES)) {
                    $classes = [...$classes, ...self::classesIn($param->type)];
                }
            }
        }

        return $classes;
    }

    /**
     * Adds the class and the classes of its properties that live in or below its namespace: a nested
     * object is filled from the same request. Entities and enums are left out, they are loaded or checked.
     *
     * @param array<string, ClassLikeStorage> $storages
     */
    private static function addInputClass(array $storages, string $class, string $namespace): void
    {
        $storage = $storages[$class] ?? null;
        if (null === $storage || isset(self::$inputClasses[$class]) || $storage->is_enum || self::isEntity($storage)) {
            return;
        }

        self::$inputClasses[$class] = true;

        foreach ($storage->properties as $property) {
            foreach (self::classesIn($property->type) as $nested) {
                if (str_starts_with($nested, $namespace)) {
                    self::addInputClass($storages, $nested, $namespace);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function taintedParameters(StatementsSource $statements, Codebase $codebase): array
    {
        $function = $statements->getSource();
        if (!$function instanceof MethodAnalyzer) {
            return [];
        }

        $methodId = (string) $function->getMethodId();
        if (isset(self::$taintedParameters[$methodId])) {
            return self::$taintedParameters[$methodId];
        }

        $method = $function->getFunctionLikeStorage();
        $class = $codebase->classlike_storage_provider->get((string) $function->getFQCLN());
        $names = [];

        if (
            self::hasAttribute($method->attributes, self::ROUTE_ATTRIBUTES)
            || ('__invoke' === strtolower((string) $method->cased_name) && self::hasAttribute($class->attributes, self::ROUTE_ATTRIBUTES))
        ) {
            foreach ($method->params as $param) {
                // A placeholder or a mapped argument; other attributes inject the user, an entity, a service...
                $mapped = [] === $param->attributes || self::hasAttribute($param->attributes, self::REQUEST_ARGUMENT_ATTRIBUTES);

                // An object is a service, an entity, the request, or a mapped object whose property reads are
                // tainted by class (see addTaints); tainting the variable too would taint its number properties
                if ($mapped && !self::isNumberOrBool($param->type) && true !== $param->type?->hasObjectType()) {
                    $names[] = $param->name;
                }
            }
        }

        return self::$taintedParameters[$methodId] = $names;
    }

    /**
     * @return lowercase-string|null
     */
    private static function inputClassOf(Union $type, Codebase $codebase): ?string
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            $class = strtolower($atomic->value);
            if (isset(self::$inputClasses[$class])) {
                return $class;
            }

            if ($codebase->classlike_storage_provider->has($class)) {
                foreach ($codebase->classlike_storage_provider->get($class)->parent_classes as $parent => $_) {
                    if (isset(self::$inputClasses[$parent])) {
                        return $class;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Null when the property is not declared: a magic or dynamic one, which can hold anything.
     *
     * @psalm-capabilities read-props
     */
    private static function propertyType(string $class, string $property, Codebase $codebase): ?Union
    {
        $storage = $codebase->classlike_storage_provider->get($class);

        foreach ([$class, ...array_keys($storage->parent_classes)] as $declaring) {
            $declared = $codebase->classlike_storage_provider->get($declaring)->properties[$property] ?? null;
            if (null !== $declared) {
                return $declared->type;
            }
        }

        return null;
    }

    /**
     * @psalm-capabilities read-props
     */
    private static function isNumberOrBool(?Union $type): bool
    {
        if (null === $type) {
            return false;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TInt && !$atomic instanceof TFloat && !$atomic instanceof TBool && !$atomic instanceof TNull) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<lowercase-string>
     *
     * @psalm-capabilities read-props
     */
    private static function classesIn(?Union $type): array
    {
        if (null === $type) {
            return [];
        }

        $classes = [];

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject) {
                $classes[] = strtolower($atomic->value);
            }

            $inner = match (true) {
                $atomic instanceof TArray, $atomic instanceof TIterable, $atomic instanceof TGenericObject => $atomic->type_params,
                $atomic instanceof TKeyedArray => [...array_values($atomic->properties), ...($atomic->fallback_params ?? [])],
                default => [],
            };

            foreach ($inner as $innerType) {
                $classes = [...$classes, ...self::classesIn($innerType)];
            }
        }

        return $classes;
    }

    /**
     * @param list<AttributeStorage> $attributes
     * @param list<string>           $names
     *
     * @psalm-pure
     */
    private static function hasAttribute(array $attributes, array $names): bool
    {
        foreach ($attributes as $attribute) {
            if (in_array(strtolower($attribute->fq_class_name), $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @psalm-capabilities read-props
     */
    private static function isEntity(ClassLikeStorage $storage): bool
    {
        return self::hasAttribute($storage->attributes, self::ENTITY_ATTRIBUTES);
    }

    /**
     * @psalm-pure
     */
    private static function namespaceOf(string $class): string
    {
        return substr($class, 0, (int) strrpos($class, '\\') + 1);
    }
}
