<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every class name written in this module's PHP source must actually resolve.
 *
 * ### Why this exists
 *
 * An unqualified class name in a namespaced file resolves against that file's
 * OWN namespace unless a `use` statement redirects it. PHP does not complain
 * about the reference — it fails only when the name is finally used, and
 * `X::class` is never "used" at all: it is a compile-time string. So a missing
 * `use` produces a plausible-looking FQCN that silently means nothing.
 *
 * Four of them shipped in `ToolsModule.php` and took the whole panel-editable
 * guides feature down, in three different ways:
 *
 *  - `ToolGuideRepository` (real: `…\Tools\Domain\…`) resolved to
 *    `Tds\Ext\Tools\ToolGuideRepository`, so the DI factory's `new` threw
 *    *Class not found* and all four guide routes fatalled.
 *  - `Throwable` resolved to `Tds\Ext\Tools\Throwable`, so the fail-soft
 *    `catch` on the public route matched nothing and a DB hiccup became a 500
 *    instead of an empty result.
 *  - `SiteCache` / `CacheEvent` (real: `Tds\Frontend\Contract\…`) resolved into
 *    this namespace, and `$c->has()` on a class that does not exist is always
 *    false — so `fireCache()` was an unconditional no-op and saving a guide
 *    never rebuilt the site's page cache.
 *
 * None of it was red. The suite covered no guide route and no cache path, the
 * doc-parity test compares only method + pattern, and the public site treats a
 * 500 as "no overrides" and renders its committed text forever. A reviewer sees
 * `catch (Throwable)` and reads it as correct.
 *
 * So the guard is not "did we fix those four" — it is "does every class name in
 * this module resolve", which also catches the next one.
 *
 * ### Why the tokenizer and not a regex
 *
 * The first version of this test scanned the source with regexes, stripping
 * comments and then strings. `#//.*$#m` also eats the `//` inside
 * `'https://api.github.com/…'`, which leaves an unterminated quote — and the
 * string-stripping pass then ran from that quote to the next one far below,
 * swallowing most of the file. It reduced ToolsModule.php from 22 KB to 4 KB
 * and reported ZERO problems on code carrying four of them. A guard that
 * under-reports in silence is the exact defect this file exists to prevent, so
 * it uses PHP's own lexer, which knows what a comment and a string are.
 */
final class ClassReferencesTest extends TestCase
{
    /** Reserved names that appear in class position but are not classes. */
    private const NOT_CLASSES = ['self', 'static', 'parent'];

    /** @return list<string> every .php file under php/src */
    private function sources(): array
    {
        $root = dirname(__DIR__) . '/src';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Resolve a written name the way PHP would inside this file.
     *
     * A leading `\` is already absolute; otherwise the first segment is looked
     * up in the `use` map; anything unmatched falls back to the file's own
     * namespace — which is precisely how the four bugs were produced.
     */
    private function resolve(string $written, string $namespace, array $uses): string
    {
        if (str_starts_with($written, '\\')) {
            return ltrim($written, '\\');
        }
        $segments = explode('\\', $written);
        $key = strtolower($segments[0]);
        if (isset($uses[$key])) {
            $segments[0] = $uses[$key];
            return implode('\\', $segments);
        }
        return $namespace === '' ? $written : $namespace . '\\' . $written;
    }

    private function exists(string $fqcn): bool
    {
        return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
    }

    /**
     * Collect the class names one file references, with the file's namespace
     * and alias map, using PHP's lexer.
     *
     * @return array{0: string, 1: array<string,string>, 2: list<string>}
     */
    private function parse(string $src): array
    {
        $tokens = token_get_all($src);
        // Drop the noise the lexer already identifies for us.
        $sig = [];
        foreach ($tokens as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $sig[] = [$t[0], $t[1]];
            } else {
                $sig[] = [null, $t];
            }
        }

        $namespace = '';
        $uses = [];
        $refs = [];
        $nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

        for ($i = 0, $n = count($sig); $i < $n; $i++) {
            [$id, $text] = $sig[$i];

            if ($id === T_NAMESPACE && isset($sig[$i + 1]) && in_array($sig[$i + 1][0], $nameTokens, true)) {
                $namespace = ltrim($sig[$i + 1][1], '\\');
                continue;
            }

            // `use A\B\C;` / `use A\B\C as D;` — but not a closure's `use (...)`
            // and not `use function` / `use const`.
            if ($id === T_USE && isset($sig[$i + 1]) && in_array($sig[$i + 1][0], $nameTokens, true)) {
                $fqcn = ltrim($sig[$i + 1][1], '\\');
                // An unqualified `use PDO;` has no separator at all, and
                // `strrpos` answers false there — which `(int)` turns into 0,
                // aliasing PDO as "DO" and so reporting a real import as
                // missing. The first version of this guard did exactly that,
                // and named three false bugs before anyone checked.
                $sep = strrpos($fqcn, '\\');
                $alias = $sep === false ? $fqcn : substr($fqcn, $sep + 1);
                if (isset($sig[$i + 2]) && $sig[$i + 2][0] === T_AS && isset($sig[$i + 3])) {
                    $alias = $sig[$i + 3][1];
                }
                $uses[strtolower($alias)] = $fqcn;
                continue;
            }

            // `Foo::class`, `Foo::CONST`, `Foo::method()`
            if (in_array($id, $nameTokens, true) && isset($sig[$i + 1]) && $sig[$i + 1][0] === T_DOUBLE_COLON) {
                $refs[] = $text;
                continue;
            }

            // `new Foo(`, `extends Foo`, `implements Foo, Bar`, `instanceof Foo`
            if (in_array($id, [T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF], true)) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (in_array($sig[$j][0], $nameTokens, true)) {
                        $refs[] = $sig[$j][1];
                        // `implements A, B` continues past a comma.
                        if (isset($sig[$j + 1]) && $sig[$j + 1][1] === ',') {
                            $j++;
                            continue;
                        }
                    }
                    break;
                }
                continue;
            }

            // `catch (Foo | Bar $e)`
            if ($id === T_CATCH) {
                for ($j = $i + 1; $j < $n && $sig[$j][1] !== ')'; $j++) {
                    if (in_array($sig[$j][0], $nameTokens, true)) {
                        $refs[] = $sig[$j][1];
                    }
                }
                continue;
            }
        }

        return [$namespace, $uses, array_values(array_unique($refs))];
    }

    public function testEveryClassReferenceResolves(): void
    {
        $problems = [];

        foreach ($this->sources() as $path) {
            $src = (string) file_get_contents($path);
            [$namespace, $uses, $refs] = $this->parse($src);

            foreach ($refs as $name) {
                if (in_array(strtolower($name), self::NOT_CLASSES, true)) {
                    continue;
                }
                $fqcn = $this->resolve($name, $namespace, $uses);
                if ($this->exists($fqcn)) {
                    continue;
                }
                $problems[] = sprintf(
                    '%s: `%s` resolves to `%s`, which does not exist',
                    basename($path),
                    $name,
                    $fqcn,
                );
            }
        }

        self::assertSame([], $problems, "Unresolvable class references:\n" . implode("\n", $problems));
    }

    /**
     * The tokenizer actually sees the file.
     *
     * The regex predecessor silently reduced ToolsModule.php to a fifth of its
     * size and then reported it clean. A scanner that finds nothing and a
     * codebase that has nothing to find are indistinguishable without this.
     */
    public function testTheScannerActuallySeesTheModule(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/ToolsModule.php');
        [$namespace, $uses, $refs] = $this->parse($src);

        self::assertSame('Tds\Ext\Tools', $namespace);
        self::assertNotEmpty($uses, 'no use statements parsed');
        self::assertGreaterThan(
            20,
            count($refs),
            'ToolsModule references far more classes than this — the scanner is blind',
        );
        self::assertContains('ToolGuideRepository', $refs);
        self::assertContains('SiteCache', $refs);
        self::assertContains('Throwable', $refs);
    }
}
