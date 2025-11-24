<?php

declare(strict_types=1);

namespace Bag2;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use function extension_loaded;
use function function_exists;
use function str_replace;
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
        $this->assertSame('new', $instance->target());
        $this->assertSame('added', $instance->added());
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
}
