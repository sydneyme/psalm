<?php
namespace Psalm;

class Clause
{
    /**
     * An array of strings of the form
     * [
     *     '$a' => ['falsy'],
     *     '$b' => ['!falsy'],
     *     '$c' => ['!null'],
     *     '$d' => ['string', 'int']
     * ]
     *
     * representing the formula
     *
     * !$a || $b || $c !== null || is_string($d) || is_int($d)
     *
     * @var array<string, array<string>>
     */
    public $possibilities;

    /**
     * An array of things that are not true
     * [
     *     '$a' => ['!falsy'],
     *     '$b' => ['falsy'],
     *     '$c' => ['null'],
     *     '$d' => ['!string', '!int']
     * ]
     * represents the formula
     *
     * $a && !$b && $c === null && !is_string($d) && !is_int($d)
     *
     * @var array<string, array<string>>|null
     */
    public $impossibilities;

    /** @var bool */
    public $wedge;

    /** @var bool */
    public $reconcilable;

    /** @var bool */
    public $generated = false;

    /**
     * @param array<string, array<string>>  $possibilities
     * @param bool                          $wedge
     * @param bool                          $reconcilable
     * @param bool                          $generated
     */
    public function __construct(array $possibilities, $wedge = false, $reconcilable = true, $generated = false)
    {
        $this->possibilities = $possibilities;
        $this->wedge = $wedge;
        $this->reconcilable = $reconcilable;
        $this->generated = $generated;
    }

    /**
     * @param  Clause $otherClause
     *
     * @return bool
     */
    public function contains(Clause $otherClause)
    {
        if (count($otherClause->possibilities) > count($this->possibilities)) {
            return false;
        }

        foreach ($otherClause->possibilities as $var => $possibleTypes) {
            if (!isset($this->possibilities[$var]) || count(array_diff($possibleTypes, $this->possibilities[$var]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gets a hash of the object – will be unique if we're unable to easily reconcile this with others
     *
     * @return string
     */
    public function getHash()
    {
        ksort($this->possibilities);

        foreach ($this->possibilities as &$possibleTypes) {
            sort($possibleTypes);
        }

        $possibilityString = json_encode($this->possibilities);
        if (!$possibilityString) {
            return (string)rand(0, 10000000);
        }

        return md5($possibilityString) .
            ($this->wedge || !$this->reconcilable ? spl_object_hash($this) : '');
    }

    public function __toString()
    {
        return implode(
            ' || ',
            array_map(
                /**
                 * @param string $varId
                 * @param string[] $values
                 *
                 * @return string
                 */
                function ($varId, $values) {
                    return implode(
                        ' || ',
                        array_map(
                            /**
                             * @param string $value
                             *
                             * @return string
                             */
                            function ($value) use ($varId) {
                                if ($value === 'falsy') {
                                    return '!' . $varId;
                                }

                                if ($value === '!falsy') {
                                    return $varId;
                                }

                                return $varId . '==' . $value;
                            },
                            $values
                        )
                    );
                },
                array_keys($this->possibilities),
                array_values($this->possibilities)
            )
        );
    }
}
