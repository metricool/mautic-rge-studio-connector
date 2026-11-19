<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Security\DummyToken;
use MauticPlugin\RgeStudioConnectorBundle\Import\ActAsUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class ActAsUserTest extends TestCase
{
    /**
     * The action runs with the user's token, and the previous token comes back afterwards.
     *
     * @return void
     */
    public function testActionRunsAsTheUserAndTheTokenComesBack(): void
    {
        $storage  = new TokenStorage();
        $previous = new DummyToken();
        $storage->setToken($previous);
        $user = new User();
        $seen = null;

        (new ActAsUser($storage))->run($user, static function () use ($storage, &$seen): void {
            $seen = $storage->getToken()?->getUser();
        });

        self::assertSame($user, $seen);
        self::assertSame($previous, $storage->getToken());
    }

    /**
     * The previous token comes back even when the action fails.
     *
     * @return void
     */
    public function testTokenComesBackWhenTheActionFails(): void
    {
        $storage = new TokenStorage();

        try {
            (new ActAsUser($storage))->run(new User(), static function (): void {
                throw new \RuntimeException('Save failed.');
            });
            self::fail('The failure was swallowed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Save failed.', $exception->getMessage());
        }

        self::assertNull($storage->getToken());
    }
}
