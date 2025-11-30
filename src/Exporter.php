<?php

declare(strict_types=1);

namespace Bag2\MonkeyPatcher;

use function class_exists;
use function dirname;
use function explode;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;

final class Exporter
{
    private MonkeyPatcher $patcher;

    public function __construct(MonkeyPatcher $patcher)
    {
        $this->patcher = $patcher;
    }

    public function writeMergedTo(string $path): void
    {
        $this->write($path, $this->patcher->getPendingCode());
    }

    public function writeOriginalTo(string $path): void
    {
        $this->write($path, $this->patcher->getOriginalCode());
    }

    public function writeUnifiedDiff(string $path): void
    {
        $original = $this->patcher->getOriginalCode();
        $merged = $this->patcher->getPendingCode();
        $header = "--- original\n+++ merged\n";

        if (class_exists(\SebastianBergmann\Diff\Differ::class)) {
            $builder = new \SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder($header);
            $differ = new \SebastianBergmann\Diff\Differ($builder);
            $diff = $differ->diff($original, $merged);
        } else {
            $diff = $header . $this->buildNaiveUnifiedDiff($original, $merged);
        }

        $this->write($path, $diff);
    }

    private function write(string $path, string $content): void
    {
        $dir = dirname($path);

        if ($dir !== '' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);
    }

    private function buildNaiveUnifiedDiff(string $original, string $merged): string
    {
        $origLines = explode("\n", $original);
        $mergedLines = explode("\n", $merged);
        $diff = [];
        $max = max(count($origLines), count($mergedLines));

        for ($i = 0; $i < $max; $i++) {
            $old = $origLines[$i] ?? null;
            $new = $mergedLines[$i] ?? null;

            if ($old === $new) {
                $diff[] = ' ' . ($old ?? '');
                continue;
            }

            if ($old !== null) {
                $diff[] = '-' . $old;
            }

            if ($new !== null) {
                $diff[] = '+' . $new;
            }
        }

        $countOld = count($origLines);
        $countNew = count($mergedLines);

        array_unshift($diff, "@@ -1,{$countOld} +1,{$countNew} @@");

        return implode("\n", $diff) . "\n";
    }
}
