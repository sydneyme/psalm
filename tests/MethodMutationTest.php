<?php
namespace Psalm\Tests;

use Psalm\Checker\FileChecker;
use Psalm\Context;

class MethodMutationTest extends TestCase
{
    /**
     * @return void
     */
    public function testControllerMutation()
    {
        $this->addFile(
            'somefile.php',
            '<?php
        class User {
            /** @var string */
            public $name;

            /**
             * @param string $name
             */
            protected function __construct($name) {
                $this->name = $name;
            }

            /** @return User|null */
            public static function loadUser(int $id) {
                if ($id === 3) {
                    $user = new User("bob");
                    return $user;
                }

                return null;
            }
        }

        class UserViewData {
            /** @var string|null */
            public $name;
        }

        class Response {
            public function __construct (UserViewData $viewdata) {}
        }

        class UnauthorizedException extends Exception { }

        class Controller {
            /** @var UserViewData */
            public $userViewdata;

            /** @var string|null */
            public $title;

            public function __construct() {
                $this->userViewdata = new UserViewData();
            }

            public function setUser(): void
            {
                $userId = (int)$_GET["id"];

                if (!$userId) {
                    throw new UnauthorizedException("No user id supplied");
                }

                $user = User::loadUser($userId);

                if (!$user) {
                    throw new UnauthorizedException("User not found");
                }

                $this->userViewdata->name = $user->name;
            }
        }

        class FooController extends Controller {
            public function barBar(): Response {
                $this->setUser();

                if (rand(0, 1)) {
                    $this->title = "hello";
                }

                return new Response($this->userViewdata);
            }
        }'
        );

        new FileChecker($this->projectChecker, 'somefile.php', 'somefile.php');
        $this->projectChecker->getCodebase()->scanFiles();
        $methodContext = new Context();
        $this->projectChecker->getMethodMutations('FooController::barBar', $methodContext);

        $this->assertSame('UserViewData', (string)$methodContext->varsInScope['$this->userViewdata']);
        $this->assertSame('string', (string)$methodContext->varsInScope['$this->userViewdata->name']);
        /** @psalm-suppress InvalidScalarArgument */
        $this->assertTrue($methodContext->varsPossiblyInScope['$this->title']);
    }

    /**
     * @return void
     */
    public function testParentControllerSet()
    {
        $this->addFile(
            'somefile.php',
            '<?php
        class Foo { }

        class Controller {
            /** @var Foo|null */
            public $foo;

            public function __construct() {
                $this->foo = new Foo();
            }
        }

        class FooController extends Controller {
            public function __construct() {
                parent::__construct();
            }
        }'
        );

        new FileChecker($this->projectChecker, 'somefile.php', 'somefile.php');
        $this->projectChecker->getCodebase()->scanFiles();
        $methodContext = new Context();
        $this->projectChecker->getMethodMutations('FooController::__construct', $methodContext);

        $this->assertSame('Foo', (string)$methodContext->varsInScope['$this->foo']);
    }

    /**
     * @return void
     */
    public function testTraitMethod()
    {
        $this->addFile(
            'somefile.php',
            '<?php
        class Foo { }

        trait T {
            private function setFoo(): void {
                $this->foo = new Foo();
            }
        }

        class FooController {
            use T;

            /** @var Foo|null */
            public $foo;

            public function __construct() {
                $this->setFoo();
            }
        }'
        );

        new FileChecker($this->projectChecker, 'somefile.php', 'somefile.php');
        $this->projectChecker->getCodebase()->scanFiles();
        $methodContext = new Context();
        $this->projectChecker->getMethodMutations('FooController::__construct', $methodContext);

        $this->assertSame('Foo', (string)$methodContext->varsInScope['$this->foo']);
    }
}
