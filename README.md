# Bag2\MonkeyPatcher

This package enables dynamic declaration addition in PHP—similar to Ruby's "open classes"—by parsing new code, comparing it to currently loaded implementations, and applying changes immediately when [uopz](https://www.php.net/manual/en/book.uopz.php) is available.

日本語版は [README-ja.md](README-ja.md) を参照してください。

## Concept

Consider this Ruby example:

```ruby
class FizzBuzzer
  def fizz(n) = "Fizz" if n % 3 == 0
  def buzz(n) = "Buzz" if n % 5 == 0
end

class FizzBuzzer
  def nass(n) = "Nass" if n % 7 == 0
end
```

Even though the class is declared twice, Ruby merges the method definitions into one open class. PHP does not allow reopening a class after its initial declaration, which is convenient for static analysis but painful in a REPL. When you change a method, you usually have to restart the REPL (e.g., [PsySH] or [`php -a`][php -a]) to pick up the new code.

`Bag2\MonkeyPatcher` uses PHP-Parser and uopz to patch methods on the fly. If uopz is not available—or disabled on purpose—it collects the merged class definitions so you can re-evaluate them after restarting your REPL.

## Usage

```php
use Bag2\MonkeyPatcher;

$patcher = new MonkeyPatcher();

// Declare the class if it does not exist yet
$patcher->patch('
class FizzBuzzer {
    public function fizz(int $n) {
        return $n % 3 === 0 ? "Hizz" : null;
    }
}', namespace: 'Foo');

// Add a new method (applied immediately when uopz is enabled)
$patcher->patch('
class FizzBuzzer {
    public function buzz(int $n) {
        return $n % 5 === 0 ? "Buzz" : null;
    }
}', namespace: 'Foo');

// Fix an implementation (applied immediately when uopz is enabled)
$patcher->patch('
class FizzBuzzer {
    public function fizz(int $n) {
        return $n % 3 === 0 ? "Fizz" : null;
    }
}', namespace: 'Foo');

// Force the non-uopz path even if the extension is loaded
$patcher->disableUopz();

// If changes could not be applied immediately, restart the REPL and re-evaluate
if ($patcher->needsRestart()) {
    $repl->restart();
    $repl->eval($patcher->getPendingCode());
}
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
