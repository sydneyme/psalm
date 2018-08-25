<?php
namespace Psalm\Storage;

use Psalm\CodeLocation;
use Psalm\Type;

class ClassLikeStorage
{
    /**
     * A lookup table for public class constants
     *
     * @var array<string, Type\Union>
     */
    public $publicClassConstants = [];

    /**
     * A lookup table for protected class constants
     *
     * @var array<string, Type\Union>
     */
    public $protectedClassConstants = [];

    /**
     * A lookup table for private class constants
     *
     * @var array<string, Type\Union>
     */
    public $privateClassConstants = [];

    /**
     * A lookup table for nodes of unresolvable public class constants
     *
     * @var array<string, \PhpParser\Node\Expr>
     */
    public $publicClassConstantNodes = [];

    /**
     * A lookup table for nodes of unresolvable protected class constants
     *
     * @var array<string, \PhpParser\Node\Expr>
     */
    public $protectedClassConstantNodes = [];

    /**
     * A lookup table for nodes of unresolvable private class constants
     *
     * @var array<string, \PhpParser\Node\Expr>
     */
    public $privateClassConstantNodes = [];

    /**
     * Aliases to help Psalm understand constant refs
     *
     * @var ?\Psalm\Aliases
     */
    public $aliases;

    /**
     * @var bool
     */
    public $populated = false;

    /**
     * @var bool
     */
    public $stubbed = false;

    /**
     * @var bool
     */
    public $deprecated = false;

    /**
     * @var array<string, bool>
     */
    public $deprecatedConstants = [];

    /**
     * @var bool
     */
    public $sealedProperties = false;

    /**
     * @var bool
     */
    public $sealedMethods = false;

    /**
     * @var array<int, string>
     */
    public $suppressedIssues = [];

    /**
     * @var string
     */
    public $name;

    /**
     * Is this class user-defined
     *
     * @var bool
     */
    public $userDefined = false;

    /**
     * Interfaces this class implements
     *
     * @var array<string, string>
     */
    public $classImplements = [];

    /**
     * Parent interfaces
     *
     * @var  array<string, string>
     */
    public $parentInterfaces = [];

    /**
     * Parent classes
     *
     * @var array<string, string>
     */
    public $parentClasses = [];

    /**
     * @var CodeLocation|null
     */
    public $location;

    /**
     * @var bool
     */
    public $abstract = false;

    /**
     * @var bool
     */
    public $final = false;

    /**
     * @var array<string, string>
     */
    public $usedTraits = [];

    /**
     * @var array<string, string>
     */
    public $traitAliasMap = [];

    /**
     * @var bool
     */
    public $isTrait = false;

    /**
     * @var bool
     */
    public $isInterface = false;

    /**
     * @var array<string, MethodStorage>
     */
    public $methods = [];

    /**
     * @var array<string, FunctionLikeStorage>
     */
    public $pseudoMethods = [];

    /**
     * @var array<string, string>
     */
    public $declaringMethodIds = [];

    /**
     * @var array<string, string>
     */
    public $appearingMethodIds = [];

    /**
     * @var array<string, array<string>>
     */
    public $overriddenMethodIds = [];

    /**
     * @var array<string, array<string>>
     */
    public $interfaceMethodIds = [];

    /**
     * @var array<string, string>
     */
    public $inheritableMethodIds = [];

    /**
     * @var array<string, PropertyStorage>
     */
    public $properties = [];

    /**
     * @var array<string, Type\Union>
     */
    public $pseudoPropertySetTypes = [];

    /**
     * @var array<string, Type\Union>
     */
    public $pseudoPropertyGetTypes = [];

    /**
     * @var array<string, string>
     */
    public $declaringPropertyIds = [];

    /**
     * @var array<string, string>
     */
    public $appearingPropertyIds = [];

    /**
     * @var array<string, string>
     */
    public $inheritablePropertyIds = [];

    /**
     * @var array<string, array<string>>
     */
    public $overriddenPropertyIds = [];

    /**
     * @var array<string, Type\Union>|null
     */
    public $templateTypes;

    /**
     * @var array<string, string>|null
     */
    public $templateParents;

    /**
     * @var array<string, array<int, CodeLocation>>|null
     */
    public $referencingLocations;

    /**
     * @var array<string, bool>
     */
    public $initializedProperties = [];

    /**
     * @var array<string>
     */
    public $invalidDependencies = [];

    /**
     * A hash of the source file's name, contents, and this file's modified on date
     *
     * @var string
     */
    public $hash = '';

    /**
     * @var bool
     */
    public $hasVisitorIssues = false;

    /**
     * @param string $name
     */
    public function __construct($name)
    {
        $this->name = $name;
    }
}
