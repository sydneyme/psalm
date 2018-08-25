<?php
namespace Psalm\Tests;

use Psalm\Context;

class VariadicTest extends TestCase
{
    use Traits\FileCheckerValidCodeParseTestTrait;

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage InvalidScalarArgument
     *
     * @return                   void
     */
    public function testVariadicArrayBadParam()
    {
        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @param array<int, int> $aList
                 * @return void
                 */
                function f(int ...$aList) {
                }
                f(1, 2, "3");
                '
        );

        $this->analyzeFile('somefile.php', new Context());
    }

    /**
     * @return array
     */
    public function providerFileCheckerValidCodeParse()
    {
        return [
            'variadic' => [
                '<?php
                /**
                 * @param mixed $req
                 * @param mixed $opt
                 * @param array<int, mixed> $params
                 * @return array<mixed>
                 */
                function f($req, $opt = null, ...$params) {
                    return $params;
                }

                f(1);
                f(1, 2);
                f(1, 2, 3);
                f(1, 2, 3, 4);
                f(1, 2, 3, 4, 5);',
            ],
            'funcNumArgsVariadic' => [
                '<?php
                    function test(): array {
                        return func_get_args();
                    }
                    var_export(test(2));',
            ],
            'variadicArray' => [
                '<?php
                    /**
                     * @param array<int, int> $aList
                     * @return array<int, int>
                     */
                    function f(int ...$aList) {
                        return array_map(
                            /**
                             * @return int
                             */
                            function (int $a) {
                                return $a + 1;
                            },
                            $aList
                        );
                    }

                    f(1);
                    f(1, 2);
                    f(1, 2, 3);

                    /**
                     * @param string ...$aList
                     * @return void
                     */
                    function g(string ...$aList) {
                    }',
            ],
        ];
    }
}
