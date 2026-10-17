<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Dto;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Security\Policy;
use Chronos\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;


final class V3FoundationTest extends TestCase
{
    private function assertApiError(int $status, string $code, callable $action): HttpError
    {
        try {
            $action();
            self::fail('The request unexpectedly succeeded.');
        } catch (HttpError $error) {
            self::assertSame($status, $error->status);
            self::assertSame($code, $error->errorCode);
            return $error;
        }
    }

    public function testConventionalUriAndQueryAreIndependentOfLegacyQueryString(): void
    {
        $request = new Request('GET', '/api/v3/projects/?search=Alpha%20Beta&page=2&archived=all');
        self::assertSame('/api/v3/projects', $request->path);
        self::assertSame(['search' => 'Alpha Beta', 'page' => '2', 'archived' => 'all'], $request->query);
    }

    public static function invalidUris(): array
    {
        return [['https://example.test/api/v3/projects'], ['/api/v3/projects%2f1'], ['/api/v3/%00'], ['/api/v3/a\\b']];
    }

    #[DataProvider('invalidUris')]
    public function testEncodedSeparatorsAndAbsoluteUrisAreRejected(string $uri): void
    {
        $this->assertApiError(400, 'invalid_uri', static fn() => new Request('GET', $uri));
    }

    public function testJsonObjectsPreserveFalseZeroUnicodeAndNullWithoutAcceptingArrays(): void
    {
        $request = new Request('PATCH', '/api/v3/profile', ['content-type' => 'application/json; charset=utf-8'], '{"active":false,"role":0,"firstName":"Éva","extra":null}');
        self::assertSame(['active' => false, 'role' => 0, 'firstName' => 'Éva', 'extra' => null], $request->json());
        $this->assertApiError(400, 'invalid_json', static fn() => (new Request('POST', '/api/v3/projects', ['content-type' => 'application/json'], '[]'))->json());
        $this->assertApiError(400, 'invalid_json', static fn() => (new Request('POST', '/api/v3/projects', ['content-type' => 'application/json'], '{'))->json());
        $this->assertApiError(415, 'json_required', static fn() => (new Request('POST', '/api/v3/projects', [], 'name=x'))->json());
        $this->assertApiError(413, 'body_too_large', static fn() => (new Request('POST', '/api/v3/projects', ['content-type' => 'application/json'], str_repeat('a', Request::MAX_BODY_BYTES + 1)))->json());
    }

    public function testMutationGuardsRequireSameOriginAndConstantTimeCsrfMatch(): void
    {
        $token = str_repeat('a', 64);
        $request = new Request('POST', '/api/v3/session', ['origin' => 'http://127.0.0.1:8088', 'x-csrf-token' => $token]);
        $request->assertSameOrigin('http', '127.0.0.1:8088');
        $request->assertCsrf($token);
        self::assertTrue(true);
        $this->assertApiError(403, 'csrf_invalid', static fn() => $request->assertCsrf(str_repeat('b', 64)));
        $this->assertApiError(403, 'csrf_invalid', static fn() => (new Request('POST', '/api/v3/session'))->assertCsrf($token));
        $this->assertApiError(403, 'cross_origin', static fn() => $request->assertSameOrigin('https', '127.0.0.1:8088'));
        $this->assertApiError(403, 'cross_origin', static fn() => (new Request('POST', '/api/v3/session', ['sec-fetch-site' => 'cross-site']))->assertSameOrigin('http', '127.0.0.1:8088'));
    }

    public function testUnsupportedMethodsHaveAllowHeader(): void
    {
        $error = $this->assertApiError(405, 'method_not_allowed', static fn() => (new Request('POST', '/api/v3/meta'))->assertMethod(['GET']));
        self::assertSame(['Allow' => 'GET'], $error->headers);
    }

    public function testListSortingAndPaginationCannotCarrySqlFragments(): void
    {
        $options = Input::listOptions(['sort' => '-name', 'archived' => 'all', 'page' => '2', 'pageSize' => '100', 'all' => 'true'], ['id' => 'p.projectId', 'name' => 'p.name']);
        self::assertSame('p.name DESC', $options['order']);
        self::assertSame(2, $options['page']);
        self::assertSame(100, $options['pageSize']);
        self::assertTrue($options['all']);
        foreach ([['sort' => 'name; DROP TABLE User'], ['page' => '-1'], ['pageSize' => '101'], ['all' => ['true']], ['sort' => '--name'], ['unknown' => 'value']] as $query) {
            $this->assertApiError(422, 'validation_failed', static fn() => Input::listOptions($query, ['id' => 'p.projectId', 'name' => 'p.name']));
        }
    }

    public function testIdentifiersRejectCoercionAndOverflow(): void
    {
        self::assertSame(42, Input::id('42', 'id'));
        foreach ([true, 0, -1, '01', '1e2', 1.5, '2147483648', [], null] as $invalid) {
            $this->assertApiError(422, 'validation_failed', static fn() => Input::id($invalid, 'id'));
        }
    }

    public function testValidationPreservesLegitimateFalseZeroEmptyAndUnicode(): void
    {
        self::assertFalse(Input::boolean(false, 'active'));
        self::assertSame(0, Input::integer(0, 'role', 0, 2));
        self::assertSame('', Input::text('', 'description', 65535, false));
        self::assertSame('Éva', Input::text(' Éva ', 'firstName', 50));
        self::assertSame(['firstname' => 'Éva', 'lastname' => "O'Brian", 'email' => 'eva@example.test', 'gender' => 2], Input::identity(['firstName' => 'Éva', 'lastName' => "O'Brian", 'email' => 'eva@example.test', 'gender' => 2], true));
        self::assertSame(['gender' => 0], Input::identity(['gender' => 0], false));
        $this->assertApiError(422, 'validation_failed', static fn() => Input::identity(['gender' => 3], false));
        $this->assertApiError(422, 'validation_failed', static fn() => Input::boolean('false', 'active'));
        $this->assertApiError(422, 'validation_failed', static fn() => Input::text("bad\0value", 'name', 50));
        $this->assertApiError(422, 'validation_failed', static fn() => Input::text("\xff", 'name', 50));
    }

    public function testPasswordRulesDoNotSilentlyTruncateBcryptOrAcceptMismatch(): void
    {
        $password = 'Chronos-test-2026!';
        self::assertSame($password, Input::password($password, $password, 12, 72, true));
        $this->assertApiError(422, 'validation_failed', static fn() => Input::password('short', 'short', 12, 72, false));
        $this->assertApiError(422, 'validation_failed', static fn() => Input::password($password, 'different', 12, 72, false));
        $this->assertApiError(422, 'validation_failed', static fn() => Input::password(str_repeat('A', 73), str_repeat('A', 73), 1, 100, false));
        $this->assertApiError(422, 'validation_failed', static fn() => Input::password('lowercase-password', 'lowercase-password', 12, 72, true));
    }

    public function testObjectPoliciesSeparateMembershipOwnershipAndAdministrativeAccess(): void
    {
        $owner = ['id' => 3, 'role' => 0];
        $member = ['id' => 4, 'role' => 0];
        $outsider = ['id' => 5, 'role' => 0];
        $admin = ['id' => 2, 'role' => 1];
        $project = ['userId' => 3];
        self::assertTrue(Policy::project($owner, $project, false));
        self::assertTrue(Policy::project($member, $project, true));
        self::assertFalse(Policy::manageProject($member, $project));
        self::assertFalse(Policy::project($outsider, $project, false));
        self::assertTrue(Policy::manageProject($admin, $project));
        self::assertFalse(Policy::system($member, ['userId' => 3]));
        self::assertTrue(Policy::system($owner, ['userId' => 3]));
        self::assertTrue(Policy::system($admin, ['userId' => 5]));
        self::assertFalse(Policy::admin(['id' => 7, 'role' => 3]));
        $this->assertApiError(403, 'forbidden', static fn() => Policy::require(false));
    }

    public function testAccountAndImpersonationPoliciesDoNotGrantOrdinaryAdminSuperadminControl(): void
    {
        $admin = ['id' => 2, 'role' => 1];
        $super = ['id' => 1, 'role' => 2];
        $target = ['userId' => 3, 'role' => 0, 'alive' => 1];
        self::assertTrue(Policy::editUser($admin, $target));
        self::assertFalse(Policy::editUser($admin, ['userId' => 1, 'role' => 2]));
        self::assertTrue(Policy::editUser($super, ['userId' => 2, 'role' => 1]));
        self::assertFalse(Policy::editUser($super, ['userId' => 1, 'role' => 2]));
        self::assertTrue(Policy::impersonate($admin, $target, false));
        self::assertFalse(Policy::impersonate($admin, $target, true));
        self::assertFalse(Policy::impersonate($admin, ['userId' => 2, 'role' => 1, 'alive' => 1], false));
        self::assertFalse(Policy::impersonate($super, ['userId' => 9, 'role' => 2, 'alive' => 1], false));
        self::assertFalse(Policy::impersonate($admin, ['userId' => 6, 'role' => 0, 'alive' => 0], false));
    }

    public function testUserDtoNeverSerializesPasswordOrRememberTokenAndMembershipDtoIsMinimal(): void
    {
        $row = ['userId' => '3', 'username' => 'owner', 'firstname' => 'Owner', 'lastname' => 'O&#039;Brian', 'email' => 'owner@example.test', 'gender' => '1', 'role' => '0', 'alive' => '1', 'activated' => '1', 'created' => '2026-01-15 12:00:00', 'lastLogin' => null, 'password' => 'SECRET-HASH', 'token' => 'SECRET-TOKEN'];
        $dto = Dto::user($row);
        self::assertSame(3, $dto['id']);
        self::assertSame("O'Brian", $dto['lastName']);
        self::assertSame('2026-01-15T12:00:00+01:00', $dto['createdAt']);
        self::assertArrayNotHasKey('password', $dto);
        self::assertArrayNotHasKey('token', $dto);
        self::assertSame(['id', 'username', 'firstName', 'lastName', 'email'], array_keys(Dto::member($row)));
    }

    public function testEventDtoReturnsTextInsteadOfLegacyHtmlAndErrorFieldsNeverEchoInput(): void
    {
        $event = Dto::event(['eventId' => '1', 'title' => '<a href="/project/detail/id=1">A &amp; B</a>', 'eventText' => '&lt;script&gt;fixture&lt;/script&gt; text', 'time' => '2026-01-15 12:00:00', 'eventType' => 'project', 'relatedId' => '1']);
        self::assertSame('A & B', $event['title']);
        self::assertSame('fixture text', $event['text']);
        self::assertSame(1, $event['resourceId']);
        $error = $this->assertApiError(422, 'validation_failed', static fn() => Input::fields(['vcsPassword' => 'SECRET'], ['name']));
        self::assertStringNotContainsString('SECRET', json_encode($error->body(), JSON_THROW_ON_ERROR));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacyCommitCannotDiscoverOrMutateTheApplicationParentRepository(): void
    {
        require_once CHRONOS_TEST_ROOT . '/libraries/vcs.php';
        // The actual source checkout is itself a Git worktree. It is still not
        // an eligible system repository and must fail before launching Git.
        foreach ([CHRONOS_TEST_ROOT, sys_get_temp_dir(), CHRONOS_TEST_ROOT . '/webroot'] as $path) {
            try {
                \VCS_Library::commit($path, 'This must never become a commit');
                self::fail('A non-system directory was accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('VCS operations require an isolated system directory.', $error->getMessage());
            }
        }
    }
}
