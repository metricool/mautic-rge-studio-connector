<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Security\DummyToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class ActAsUser
{
    /**
     * Build the helper.
     *
     * @param TokenStorageInterface $tokenStorage Security token storage
     */
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    /**
     * Run an action as the given user, then put the previous security token back.
     *
     * The token is restored before the response, so no session or cookie is created for the caller.
     *
     * @template T
     *
     * @param User          $user   User to act as
     * @param callable(): T $action Action to run
     *
     * @return T What the action returns
     */
    public function run(User $user, callable $action): mixed
    {
        $previous = $this->tokenStorage->getToken();
        $token    = new DummyToken();
        $token->setUser($user);
        $this->tokenStorage->setToken($token);

        try {
            return $action();
        } finally {
            $this->tokenStorage->setToken($previous);
        }
    }
}
