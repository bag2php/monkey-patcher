<?php

declare(strict_types=1);

namespace Bag2\MonkeyPatcher;

use Closure;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use PhpParser\NodeVisitor\CloningVisitor;
use ReflectionFunction;
use function class_exists;
use function extension_loaded;
use function file;
use function file_exists;
use function function_exists;
use function implode;
use function is_array;
use function method_exists;

final class MonkeyPatcher
{
    private Parser $parser;
    private PrettyPrinter\Standard $printer;
    private bool $hasUopz;
    private bool $needsRestart = false;
    /** @var array<string, array{namespace: string|null, uses: Node\Stmt[], class: Node\Stmt\Class_}> */
    private array $rawClasses = [];
    /** @var array<string, array{namespace: string|null, uses: Node\Stmt[], class: Node\Stmt\Class_}> */
    private array $originalClasses = [];
    /** @var array<string, array{namespace: string|null, uses: Node\Stmt[], function: Node\Stmt\Function_}> */
    private array $rawFunctions = [];
    /** @var array<string, array{namespace: string|null, uses: Node\Stmt[], function: Node\Stmt\Function_}> */
    private array $originalFunctions = [];

    public function __construct(?Parser $parser = null, ?PrettyPrinter\Standard $printer = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = $printer ?? new PrettyPrinter\Standard();
        $this->hasUopz = extension_loaded('uopz') && function_exists('uopz_add_function');
    }

    public function patch(string $code, ?string $namespace = null): void
    {
        $fullCode = $this->prependNamespace($code, $namespace);
        $definitions = $this->extractDefinitions($fullCode);
        $classDefinitions = $definitions['classes'];
        $functionDefinitions = $definitions['functions'];

        foreach ($classDefinitions as $definition) {
            $className = $definition['fqcn'];
            $classExists = class_exists($className);

            if (!$classExists) {
                $this->declareClass($definition['class'], $definition['namespace'], $definition['uses']);
                $classExists = true;
            }

            foreach ($definition['methods'] as $methodName => $methodNode) {
                $existing = $this->getReflectedMethodAst($className, $methodName);

                if ($existing !== null && $this->nodesEqual($existing, $methodNode)) {
                    continue;
                }

                if ($this->hasUopz) {
                    $this->addMethodWithUopz($className, $methodNode);
                    continue;
                }

                $this->needsRestart = true;
            }
        }

        foreach ($functionDefinitions as $definition) {
            $functionName = $definition['fqfn'];
            $functionExists = function_exists($functionName);

            if (!$functionExists) {
                $this->declareFunction($definition['function'], $definition['namespace'], $definition['uses']);
                $functionExists = true;
            }

            if ($functionExists) {
                $existing = $this->getReflectedFunctionAst($functionName);

                if ($existing !== null && $this->nodesEqual($existing, $definition['function'])) {
                    continue;
                }

                if ($this->hasUopz) {
                    $this->addFunctionWithUopz($functionName, $definition['function']);
                    continue;
                }

                $this->needsRestart = true;
            }
        }

        foreach ($classDefinitions as $definition) {
            $this->storeRawClass($definition);
        }

        foreach ($functionDefinitions as $definition) {
            $this->storeRawFunction($definition);
        }
    }

    public function needsRestart(): bool
    {
        return $this->needsRestart;
    }

    public function isUopzAvailable(): bool
    {
        return $this->hasUopz;
    }

    public function disableUopz(): void
    {
        $this->hasUopz = false;
    }

    public function getPendingCode(): string
    {
        return $this->buildSources($this->rawFunctions, $this->rawClasses);
    }

    public function getOriginalCode(): string
    {
        return $this->buildSources($this->originalFunctions, $this->originalClasses);
    }

    private function prependNamespace(string $code, ?string $namespace): string
    {
        $namespaceLine = $namespace === null || $namespace === ''
            ? ''
            : "namespace {$namespace};\n";

        return "<?php\n{$namespaceLine}" . trim($code) . "\n";
    }

    /**
     * @return array{
     *     classes: list<array{fqcn: string, namespace: string|null, class: Node\Stmt\Class_, methods: array<string, Node\Stmt\ClassMethod>, uses: Node\Stmt[]}>,
     *     functions: list<array{fqfn: string, namespace: string|null, function: Node\Stmt\Function_, uses: Node\Stmt[]}>,
     * }
     */
    private function extractDefinitions(string $code): array
    {
        $statements = $this->parser->parse($code) ?? [];
        $classes = [];
        $functions = [];
        $useStatements = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Use_ || $statement instanceof Node\Stmt\GroupUse) {
                $useStatements[] = $statement;
                continue;
            }

            if ($statement instanceof Node\Stmt\Namespace_) {
                $collected = $this->collectMembers(
                    $statement->stmts,
                    isset($statement->name) ? $statement->name->toString() : null,
                );
                $classes = [...$classes, ...$collected['classes']];
                $functions = [...$functions, ...$collected['functions']];
                continue;
            }

            if ($statement instanceof Node\Stmt\Class_) {
                $classes[] = $this->buildClassDefinition($statement, null, $useStatements);
                continue;
            }

            if ($statement instanceof Node\Stmt\Function_) {
                $functions[] = $this->buildFunctionDefinition($statement, null, $useStatements);
            }
        }

        return ['classes' => $classes, 'functions' => $functions];
    }

    /**
     * @param Node\Stmt[] $stmts
     * @return array{
     *     classes: list<array{fqcn: string, namespace: string|null, class: Node\Stmt\Class_, methods: array<string, Node\Stmt\ClassMethod>, uses: Node\Stmt[]}>,
     *     functions: list<array{fqfn: string, namespace: string|null, function: Node\Stmt\Function_, uses: Node\Stmt[]}>,
     * }
     */
    private function collectMembers(array $stmts, ?string $namespace): array
    {
        $classes = [];
        $functions = [];
        $useStatements = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Use_ || $stmt instanceof Node\Stmt\GroupUse) {
                $useStatements[] = $stmt;
                continue;
            }

            if ($stmt instanceof Node\Stmt\Class_) {
                $classes[] = $this->buildClassDefinition($stmt, $namespace, $useStatements);
                continue;
            }

            if ($stmt instanceof Node\Stmt\Function_) {
                $functions[] = $this->buildFunctionDefinition($stmt, $namespace, $useStatements);
            }
        }

        return ['classes' => $classes, 'functions' => $functions];
    }

    /**
     * @param list<Node\Stmt\GroupUse|Node\Stmt\Use_> $useStatements
     * @return array{fqcn: string, namespace: string|null, class: Node\Stmt\Class_, methods: array<string, Node\Stmt\ClassMethod>, uses: Node\Stmt[]}
     */
    private function buildClassDefinition(Node\Stmt\Class_ $class, ?string $namespace, array $useStatements): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            $methods[$method->name->toString()] = $method;
        }

        $shortName = isset($class->name) ? $class->name->toString() : '';
        $fqcn = $namespace ? "{$namespace}\\{$shortName}" : $shortName;

        return [
            'fqcn' => $fqcn,
            'namespace' => $namespace,
            'class' => $class,
            'methods' => $methods,
            'uses' => $useStatements,
        ];
    }

    /**
     * @param list<Node\Stmt\GroupUse|Node\Stmt\Use_> $useStatements
     * @return array{fqfn: string, namespace: string|null, function: Node\Stmt\Function_, uses: Node\Stmt[]}
     */
    private function buildFunctionDefinition(Node\Stmt\Function_ $function, ?string $namespace, array $useStatements): array
    {
        $name = $function->name->toString();
        $fqfn = $namespace ? "{$namespace}\\{$name}" : $name;

        return [
            'fqfn' => $fqfn,
            'namespace' => $namespace,
            'function' => $function,
            'uses' => $useStatements,
        ];
    }

    /**
     * @param Node\Stmt[] $useStatements
     */
    private function declareClass(Node\Stmt\Class_ $class, ?string $namespace, array $useStatements): void
    {
        $code = $this->buildClassSource($class, $namespace, $useStatements);

        eval($code);
    }

    /**
     * @param Node\Stmt[] $useStatements
     */
    private function declareFunction(Node\Stmt\Function_ $function, ?string $namespace, array $useStatements): void
    {
        $code = $this->buildFunctionSource($function, $namespace, $useStatements);

        eval($code);
    }

    private function getReflectedMethodAst(string $className, string $methodName): ?Node\Stmt\ClassMethod
    {
        if (!class_exists($className) || !method_exists($className, $methodName)) {
            return null;
        }

        $reflection = new \ReflectionMethod($className, $methodName);
        $fileName = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if (!$fileName || !file_exists($fileName) || $startLine === false || $endLine === false) {
            return null;
        }

        $lines = file($fileName);

        if ($lines === false) {
            return null;
        }

        $methodLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
        $stub = "<?php class __MonkeyPatcherStub__ { \n" . implode('', $methodLines) . "\n}";
        $stmts = $this->parser->parse($stub);

        if ($stmts === null) {
            return null;
        }

        $nodeFinder = new NodeFinder();
        $class = $nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);

        if (!$class instanceof Node\Stmt\Class_) {
            return null;
        }

        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() === $methodName) {
                return $method;
            }
        }

        return null;
    }

    private function getReflectedFunctionAst(string $functionName): ?Node\Stmt\Function_
    {
        if (!function_exists($functionName)) {
            return null;
        }

        $reflection = new ReflectionFunction($functionName);
        $fileName = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if (!$fileName || !file_exists($fileName) || $startLine === false || $endLine === false) {
            return null;
        }

        $lines = file($fileName);

        if ($lines === false) {
            return null;
        }

        $functionLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
        $stub = "<?php\n" . implode('', $functionLines);
        $stmts = $this->parser->parse($stub);

        if ($stmts === null) {
            return null;
        }

        $nodeFinder = new NodeFinder();
        $function = $nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\Function_::class);

        if (!$function instanceof Node\Stmt\Function_) {
            return null;
        }

        return $function;
    }

    private function nodesEqual(Node $left, Node $right): bool
    {
        return $this->normalizeNode($left) === $this->normalizeNode($right);
    }

    private function normalizeNode(Node $node): string
    {
        $cloned = $this->cloneNode($node);
        $this->stripAttributes($cloned);

        return serialize($cloned);
    }

    private function stripAttributes(Node $node): void
    {
        $node->setAttributes([]);

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;

            if ($child instanceof Node) {
                $this->stripAttributes($child);
                continue;
            }

            if (!is_array($child)) {
                continue;
            }

            foreach ($child as $item) {
                if ($item instanceof Node) {
                    $this->stripAttributes($item);
                }
            }
        }
    }

    private function addMethodWithUopz(string $className, Node\Stmt\ClassMethod $method): void
    {
        $methodName = $method->name->toString();
        $closureNode = new Node\Expr\Closure([
            'static' => $method->isStatic(),
            'byRef' => $method->byRef,
            'params' => $method->params,
            'returnType' => $method->returnType,
            'stmts' => $method->stmts ?? [],
            'attrGroups' => $method->attrGroups,
        ]);

        $code = $this->printer->prettyPrintExpr($closureNode);
        /** @var Closure $closure */
        $closure = eval("return {$code};");

        $flags = $this->resolveFlags($method);
        if (method_exists($className, $methodName)) {
            if (function_exists('uopz_set_return')) {
                uopz_set_return($className, $methodName, $closure, true);
                return;
            }

            if (function_exists('uopz_del_function')) {
                try {
                    uopz_del_function($className, $methodName);
                } catch (\Throwable $e) {
                    $this->needsRestart = true;
                    return;
                }
            } elseif (function_exists('uopz_delete')) {
                try {
                    uopz_delete($className, $methodName);
                } catch (\Throwable $e) {
                    $this->needsRestart = true;
                    return;
                }
            } else {
                $this->needsRestart = true;
                return;
            }
        }

        // @phpstan-ignore argument.type (Need to fix PHPStan upstream.)
        uopz_add_function($className, $methodName, $closure, $flags);
    }

    private function addFunctionWithUopz(string $functionName, Node\Stmt\Function_ $function): void
    {
        $closureNode = new Node\Expr\Closure([
            'byRef' => $function->byRef,
            'params' => $function->params,
            'returnType' => $function->returnType,
            'stmts' => $function->stmts ?? [],
            'attrGroups' => $function->attrGroups,
        ]);

        $code = $this->printer->prettyPrintExpr($closureNode);
        /** @var Closure $closure */
        $closure = eval("return {$code};");

        if (function_exists($functionName)) {
            if (function_exists('uopz_set_return')) {
                uopz_set_return($functionName, $closure, true);
                return;
            }

            if (function_exists('uopz_del_function')) {
                try {
                    uopz_del_function($functionName);
                } catch (\Throwable $e) {
                    $this->needsRestart = true;
                    return;
                }
            } elseif (function_exists('uopz_delete')) {
                try {
                    uopz_delete($functionName);
                } catch (\Throwable $e) {
                    $this->needsRestart = true;
                    return;
                }
            } else {
                $this->needsRestart = true;
                return;
            }
        }

        uopz_add_function($functionName, $closure);
    }

    /**
     * @param Node\Stmt[] $useStatements
     */
    private function buildClassSource(Node\Stmt\Class_ $class, ?string $namespace, array $useStatements): string
    {
        $stmts = [...$useStatements, $class];
        $code = $this->printer->prettyPrint($stmts);

        if ($namespace) {
            return "namespace {$namespace};\n{$code}";
        }

        return $code;
    }

    /**
     * @param Node\Stmt[] $useStatements
     */
    private function buildFunctionSource(Node\Stmt\Function_ $function, ?string $namespace, array $useStatements): string
    {
        $stmts = [...$useStatements, $function];
        $code = $this->printer->prettyPrint($stmts);

        if ($namespace) {
            return "namespace {$namespace};\n{$code}";
        }

        return $code;
    }

    /**
     * @param array<string, array{namespace: string|null, uses: Node\Stmt[], function: Node\Stmt\Function_}> $functions
     * @param array<string, array{namespace: string|null, uses: Node\Stmt[], class: Node\Stmt\Class_}> $classes
     */
    private function buildSources(array $functions, array $classes): string
    {
        $chunks = [];

        foreach ($functions as $function) {
            $chunks[] = $this->buildFunctionSource($function['function'], $function['namespace'], $function['uses']);
        }

        foreach ($classes as $class) {
            $chunks[] = $this->buildClassSource($class['class'], $class['namespace'], $class['uses']);
        }

        return implode(PHP_EOL . PHP_EOL, $chunks);
    }

    private function resolveFlags(Node\Stmt\ClassMethod $method): int
    {
        $flags = ZEND_ACC_PUBLIC;

        if ($method->isProtected()) {
            $flags = ZEND_ACC_PROTECTED;
        } elseif ($method->isPrivate()) {
            $flags = ZEND_ACC_PRIVATE;
        }

        if ($method->isStatic()) {
            $flags |= ZEND_ACC_STATIC;
        }

        if ($method->isAbstract()) {
            $flags |= ZEND_ACC_ABSTRACT;
        }

        if ($method->isFinal()) {
            $flags |= ZEND_ACC_FINAL;
        }

        return $flags;
    }

    /** @param array{fqcn: string, namespace: string|null, class: Node\Stmt\Class_, methods: array<string, Node\Stmt\ClassMethod>, uses: list<Node\Stmt>} $definition */
    private function storeRawClass(array $definition): void
    {
        $fqcn = $definition['fqcn'];

        if (!isset($this->rawClasses[$fqcn])) {
            $this->rawClasses[$fqcn] = [
                'namespace' => $definition['namespace'],
                'uses' => $this->cloneNodes($definition['uses']),
                'class' => $this->cloneNode($definition['class']),
            ];
            $this->originalClasses[$fqcn] = [
                'namespace' => $definition['namespace'],
                'uses' => $this->cloneNodes($definition['uses']),
                'class' => $this->cloneNode($definition['class']),
            ];
            return;
        }

        $existing = $this->rawClasses[$fqcn];
        $classNode = $existing['class'];

        foreach ($definition['methods'] as $method) {
            $this->mergeMethod($classNode, $method);
        }

        $this->rawClasses[$fqcn] = [
            'namespace' => $definition['namespace'] ?? $existing['namespace'],
            'uses' => $this->mergeUses($existing['uses'], $definition['uses']),
            'class' => $classNode,
        ];
    }

    /** @param array{fqfn: string, namespace: string|null, function: Node\Stmt\Function_, uses: list<Node\Stmt>} $definition */
    private function storeRawFunction(array $definition): void
    {
        $fqfn = $definition['fqfn'];

        if (!isset($this->rawFunctions[$fqfn])) {
            $this->rawFunctions[$fqfn] = [
                'namespace' => $definition['namespace'],
                'uses' => $this->cloneNodes($definition['uses']),
                'function' => $this->cloneNode($definition['function']),
            ];
            $this->originalFunctions[$fqfn] = [
                'namespace' => $definition['namespace'],
                'uses' => $this->cloneNodes($definition['uses']),
                'function' => $this->cloneNode($definition['function']),
            ];
            return;
        }

        $existing = $this->rawFunctions[$fqfn];

        $this->rawFunctions[$fqfn] = [
            'namespace' => $definition['namespace'] ?? $existing['namespace'],
            'uses' => $this->mergeUses($existing['uses'], $definition['uses']),
            'function' => $this->cloneNode($definition['function']),
        ];
    }

    private function mergeMethod(Node\Stmt\Class_ $class, Node\Stmt\ClassMethod $method): void
    {
        $method = $this->cloneNode($method);

        foreach ($class->stmts as $index => $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === $method->name->toString()) {
                $class->stmts[$index] = $method;
                return;
            }
        }

        $class->stmts[] = $method;
    }

    /**
     * @param Node\Stmt[] $left
     * @param Node\Stmt[] $right
     * @return Node\Stmt[]
     */
    private function mergeUses(array $left, array $right): array
    {
        $hashes = [];
        $merged = [];

        foreach ([$left, $right] as $group) {
            foreach ($group as $stmt) {
                $hash = $this->normalizeNode($stmt);

                if (isset($hashes[$hash])) {
                    continue;
                }

                $hashes[$hash] = true;
                $merged[] = $this->cloneNode($stmt);
            }
        }

        return $merged;
    }

    /**
     * @template T of Node
     * @param list<T> $nodes
     * @return list<T>
     */
    private function cloneNodes(array $nodes): array
    {
        $traverser = new NodeTraverser(new CloningVisitor());
        /** @var list<T> */
        return $traverser->traverse($nodes);
    }

    /**
     * @template T of Node
     * @param T $node
     * @return T
     */
    private function cloneNode(Node $node): Node
    {
        return $this->cloneNodes([$node])[0];
    }
}
