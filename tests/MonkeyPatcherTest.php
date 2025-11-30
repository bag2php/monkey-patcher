<?php

declare(strict_types=1);

namespace Bag2\MonkeyPatcher;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use function extension_loaded;
use function function_exists;
use function file_get_contents;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;
use function uniqid;

#[CoversClass(MonkeyPatcher::class)]
final class MonkeyPatcherTest extends TestCase
{
    public function testNeedsRestartWhenUopzDisabled(): void
    {
        $className = 'Sample_' . str_replace('.', '_', uniqid('', true));
        $patcher = new MonkeyPatcher();
        $patcher->disableUopz();

        $patcher->patch(<<<PHP
            class {$className} {
                public function greet(): string {
                    return 'hello';
                }
            }
            PHP);

        $this->assertTrue($patcher->needsRestart());
        $expected = <<<PHP
            class {$className}
            {
                public function greet(): string
                {
                    return 'hello';
                }
            }
            PHP;
        $this->assertSame($expected, $patcher->getPendingCode());
    }

    public function testAddsOrOverridesMethodsWithUopz(): void
    {
        if (!extension_loaded('uopz') || !function_exists('uopz_add_function')) {
            $this->markTestSkipped('uopz extension is not available');
        }

        $className = 'SampleUopz_' . str_replace('.', '_', uniqid('', true));
        eval("class {$className} { public function target(): string { return 'old'; } }");

        $patcher = new MonkeyPatcher();
        $patcher->patch(<<<PHP
            class {$className} {
                public function target(): string {
                    return 'new';
                }
                public function added(): string {
                    return 'added';
                }
            }
            PHP);

        $instance = new $className();

        $this->assertFalse($patcher->needsRestart());
        $this->assertSame('new', $instance->target()); // @phpstan-ignore method.notFound
        $this->assertSame('added', $instance->added()); // @phpstan-ignore method.notFound
    }

    public function testCollectsPatchesAcrossNamespacesWhenUopzDisabled(): void
    {
        $fooShort = 'Alpha_' . str_replace('.', '_', uniqid('', true));
        $barShort = 'Beta_' . str_replace('.', '_', uniqid('', true));

        eval(<<<PHP
            namespace Foo;
            class {$fooShort} {
                public function one(): string {
                    return 'one';
                }
            }
            PHP);

        eval(<<<PHP
            namespace Bar;
            class {$barShort} {
                public function one(): string {
                    return 'one';
                }
            }
            PHP);

        $patcher = new MonkeyPatcher();
        $patcher->disableUopz();

        $patcher->patch(<<<PHP
            class {$fooShort} {
                public function one(): string {
                    return 'ONE';
                }
            }
            PHP, namespace: 'Foo');

        $patcher->patch(<<<PHP
            class {$barShort} {
                public function one(): string {
                    return 'two';
                }
            }
            PHP, namespace: 'Bar');

        $this->assertTrue($patcher->needsRestart());

        $expected = <<<PHP
            namespace Foo;
            class {$fooShort}
            {
                public function one(): string
                {
                    return 'ONE';
                }
            }

            namespace Bar;
            class {$barShort}
            {
                public function one(): string
                {
                    return 'two';
                }
            }
            PHP;
        $this->assertSame($expected, $patcher->getPendingCode());
    }

    public function testExportsMergedOriginalAndDiff(): void
    {
        $patcher = new MonkeyPatcher();
        $patcher->disableUopz();
        $className = 'Export_' . str_replace('.', '_', uniqid('', true));

        $patcher->patch(<<<PHP
            class {$className} {
                public function value(): string {
                    return "old";
                }
            }
            PHP);

        $patcher->patch(<<<PHP
            class {$className} {
                public function value(): string {
                    return "new";
                }
            }
            PHP);

        $exporter = new Exporter($patcher);
        $tmp = sys_get_temp_dir();
        $origPath = "{$tmp}/monkey-original-{$className}.php";
        $mergedPath = "{$tmp}/monkey-merged-{$className}.php";
        $diffPath = "{$tmp}/monkey-diff-{$className}.patch";

        $exporter->writeOriginalTo($origPath);
        $exporter->writeMergedTo($mergedPath);
        $exporter->writeUnifiedDiff($diffPath);

        $this->assertSame($patcher->getOriginalCode(), file_get_contents($origPath));
        $this->assertSame($patcher->getPendingCode(), file_get_contents($mergedPath));

        $diff = file_get_contents($diffPath);
        assert($diff !== false);
        $this->assertStringContainsString('--- original', $diff);
        $this->assertStringContainsString('+++ merged', $diff);
        $this->assertStringContainsString('value(): string', $diff);
        $this->assertStringContainsString('return "old";', $diff);
        $this->assertStringContainsString('return "new";', $diff);

        unlink($origPath);
        unlink($mergedPath);
        unlink($diffPath);
    }

    /** @dataProvider provideDocCommentScenarios */
    #[DataProvider('provideDocCommentScenarios')]
    public function testDocCommentHandling(string $firstPatch, ?string $secondPatch, string $expected): void
    {
        $patcher = new MonkeyPatcher();
        $patcher->disableUopz();

        $patcher->patch($firstPatch);

        if ($secondPatch !== null) {
            $patcher->patch($secondPatch);
        }

        $this->assertSame($expected, $patcher->getPendingCode());
    }

    /** @return iterable<list{string, ?string, string}> */
    public static function provideDocCommentScenarios(): iterable
    {
        $classWithDoc = 'Doc_' . str_replace('.', '_', uniqid('', true));
        $classUpdate = 'DocUpdate_' . str_replace('.', '_', uniqid('', true));

        $keepDocPatch = <<<PHP
            /**
             * Sample doc
             */
            class {$classWithDoc} {
                /**
                 * Say hello
                 */
                public function hello(): string {
                    return "hello";
                }
            }
            PHP;
        $keepDocExpected = <<<PHP
            /**
             * Sample doc
             */
            class {$classWithDoc}
            {
                /**
                 * Say hello
                 */
                public function hello(): string
                {
                    return "hello";
                }
            }
            PHP;

        yield 'keeps-doc-comment' => [$keepDocPatch, null, $keepDocExpected];

        $updateDocFirst = <<<PHP
            class {$classUpdate} {
                public function hello(): string {
                    return "old";
                }
            }
            PHP;
        $updateDocSecond = <<<PHP
            class {$classUpdate} {
                /**
                 * Updated doc
                 */
                public function hello(): string {
                    return "new";
                }
            }
            PHP;
        $updateDocExpected = <<<PHP
            class {$classUpdate}
            {
                /**
                 * Updated doc
                 */
                public function hello(): string
                {
                    return "new";
                }
            }
            PHP;

        yield 'updates-doc-comment' => [$updateDocFirst, $updateDocSecond, $updateDocExpected];
    }
}
