# Bag2\MonkeyPatcher

PHPで「オープンクラス」を実現するための実行時パッチャーです。PHP-Parserで新旧コードを解析し、uopzが使える場合はメソッドや関数をクロージャ化して動的に差し替えます。uopzが無効な場合でも、再評価に必要なコードを蓄えてREPL再起動後に適用できます。

## コンセプト

以下のRubyコードを見てください：

```ruby
class FizzBuzzer
  def fizz(n) = "Fizz" if n % 3 == 0
  def buzz(n) = "Buzz" if n % 5 == 0
end

class FizzBuzzer
  def nass(n) = "Nass" if n % 7 == 0
end
```

クラス宣言は2つに分かれていますが、実際にはメソッド定義が一つのクラスにマージされます。このように一度定義されたメソッドを「オープンクラス」と呼ばれます。

一方で、PHPは一度定義されたクラスに宣言を追加することを許容していません。これは静的解析のためにクラスを調査するために複数のファイルや動的な宣言追加を考慮する必要がないのでとても便利です。これは同時に、[REPL]ベースでコーディングする際の明確な痛みでもあります。あなたはメソッドの実装を変更したあと、REPL([PsySH]や[`php -a`][php -a])のプロセスを終了させて、再起動しないとコードの変更が反映されません。

`Bag2\MonkeyPatcher`はPHPの動的な機能を活用して極力再起動なしでメソッドを差し替えます。uopzが無効または意図的に無効化している場合は、再評価用のコードを保持して`needsRestart()`で再起動が必要か判定できます。

## 使用例

```php
use Bag2\MonkeyPatcher\MonkeyPatcher;

$patcher = new MonkeyPatcher();

// まだ Foo\FizzBuzzer クラスが宣言されていなければ新しいクラスとして宣言されます
$patcher->patch('
class FizzBuzzer {
    public function fizz(int $n) {
        return $n % 3 === 0 ? "Hizz" : null;
    }
}', namespace: 'Foo');

// メソッドを追加します (uopzが有効なら即時有効化)
$patcher->patch('
class FizzBuzzer {
    public function buzz(int $n) {
        return $n % 5 === 0 ? "Buzz" : null;
    }
}', namespace: 'Foo');

// メソッドの実装を訂正します (uopzが有効なら即時有効化)
$patcher->patch('
class FizzBuzzer {
    public function fizz(int $n) {
        return $n % 3 === 0 ? "Fizz" : null;
    }
}', namespace: 'Foo');

// 関数の実装を差し替えつつ新しい関数も追加します
$patcher->patch('
function fizz(int $n) {
    return $n % 3 === 0 ? "Fizz" : null;
}

function buzz(int $n) {
    return $n % 5 === 0 ? "Buzz" : null;
}', namespace: 'Foo');

// uopzを強制無効化したい場合
$patcher->disableUopz();

// メソッド変更が即時反映されていなければ、REPLのプロセスを再起動してクラスのコードを評価させます
if ($patcher->needsRestart()) {
    $repl->restart();
    $repl->eval($patcher->getPendingCode());
}

// マージ後コードや元コード、diffをファイルに書き出す
$exporter = new \Bag2\MonkeyPatcher\Exporter($patcher);
$exporter->writeMergedTo('/tmp/monkey-merged.php');
$exporter->writeOriginalTo('/tmp/monkey-original.php');
$exporter->writeUnifiedDiff('/tmp/monkey.patch');
```

## Copyright

> Copyright 2025 USAMI Kenta
>
> Licensed under the Apache License, Version 2.0 (the "License");
> you may not use this file except in compliance with the License.
> You may obtain a copy of the License at
>
>     http://www.apache.org/licenses/LICENSE-2.0
>
> Unless required by applicable law or agreed to in writing, software
> distributed under the License is distributed on an "AS IS" BASIS,
> WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
> See the License for the specific language governing permissions and
> limitations under the License.

[REPL]: https://en.wikipedia.org/wiki/Read%E2%80%93eval%E2%80%93print_loop
[PsySH]: https://psysh.org/
[php -a]: https://www.php.net/manual/features.commandline.interactive.php
