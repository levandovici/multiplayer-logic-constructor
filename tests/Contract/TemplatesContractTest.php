<?php

namespace Mlc\Tests\Contract;

use Mlc\Engine\Program;
use PHPUnit\Framework\TestCase;

// Contract guard: every shippable template must pass the same strict
// validation a publish does — a broken template would create a game whose
// published logic can never run. Templates are built by
// bin/build-templates.mjs (Blockly workspace → AST via the real generator).
class TemplatesContractTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function astFiles(): iterable
    {
        $dir = __DIR__ . '/../../templates';
        foreach (glob("$dir/*.ast.json") ?: [] as $f) {
            yield basename($f) => [$f];
        }
    }

    /** @dataProvider astFiles */
    public function testTemplateAstIsPublishable(string $file): void
    {
        Program::fromJson((string)file_get_contents($file));
        $raw = json_decode((string)file_get_contents($file), true);
        $this->assertNotEmpty($raw['triggers'] ?? [], basename($file) . ' has no triggers');
    }

    /** Every template has both artifacts — workspace (editor) + AST (runtime). */
    public function testWorkspaceAndAstPairs(): void
    {
        $dir = __DIR__ . '/../../templates';
        $this->assertNotEmpty(glob("$dir/*.ast.json"), 'no templates built — run node bin/build-templates.mjs');
        foreach (glob("$dir/*.ast.json") ?: [] as $ast) {
            $ws = substr($ast, 0, -strlen('.ast.json')) . '.workspace.json';
            $this->assertFileExists($ws, basename($ws) . ' missing for ' . basename($ast));
        }
        foreach (glob("$dir/*.workspace.json") ?: [] as $ws) {
            $ast = substr($ws, 0, -strlen('.workspace.json')) . '.ast.json';
            $this->assertFileExists($ast, basename($ast) . ' missing for ' . basename($ws));
        }
    }

    /** Each workspace must reference only block types the editor defines. */
    public function testWorkspaceBlockTypesExist(): void
    {
        // Block types appear as literal `type: 'x'` (map_lit/list_lit) or as
        // the first arg of hat()/stmt()/expr() helpers.
        $js = (string)file_get_contents(__DIR__ . '/../../public_html/js/mlc-blocks.js');
        preg_match_all("/type:\s*'([a-z_]+)'/", $js, $m);
        preg_match_all("/(?:hat|stmt|expr)\(\s*'([a-z_]+)'/", $js, $m2);
        $known = array_flip(array_merge($m[1], $m2[1]));

        foreach (glob(__DIR__ . '/../../templates/*.workspace.json') ?: [] as $f) {
            $ws = json_decode((string)file_get_contents($f), true);
            $this->assertIsArray($ws, basename($f) . ' is not valid JSON');
            $types = [];
            array_walk_recursive($ws, function ($v, $k) use (&$types) {
                if ($k === 'type' && is_string($v)) { $types[$v] = true; }
            });
            foreach ($types as $t => $_) {
                $this->assertArrayHasKey($t, $known,
                    basename($f) . " uses unknown block type '$t'");
            }
        }
    }
}
