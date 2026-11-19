<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Mautic\EmailBundle\Entity\Email;
use Mautic\UserBundle\Entity\User;

class NoImportProject implements ImportProject
{
    /**
     * Nothing to check: this Mautic has no projects.
     *
     * @return void
     */
    public function check(): void
    {
    }

    /**
     * No project to find or create.
     *
     * @param User $user Import user
     *
     * @return int
     */
    public function prepare(User $user): int
    {
        return 0;
    }

    /**
     * Leave the email as it is.
     *
     * @param Email $email     Email being saved
     * @param int   $projectId Project id from prepare()
     *
     * @return void
     */
    public function place(Email $email, int $projectId): void
    {
    }
}
