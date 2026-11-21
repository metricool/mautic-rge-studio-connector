<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Mautic\EmailBundle\Entity\Email;
use Mautic\UserBundle\Entity\User;

interface ImportProject
{
    /**
     * Check the project setting.
     *
     * @return void
     *
     * @throws ConnectorConfigurationException When the project name is not valid
     */
    public function check(): void;

    /**
     * Find the import project, or create it as the import user.
     *
     * @param User $user Import user
     *
     * @return int Project id, 0 when this Mautic has no projects
     *
     * @throws ConnectorConfigurationException When the project name is not valid or the project cannot be created
     */
    public function prepare(User $user): int;

    /**
     * Put the email into the import project when it is not in it yet.
     *
     * @param Email $email     Email being saved
     * @param int   $projectId Project id from prepare()
     *
     * @return void
     */
    public function place(Email $email, int $projectId): void;
}
