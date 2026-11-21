<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Mautic\EmailBundle\Entity\Email;
use Mautic\ProjectBundle\Entity\Project;
use Mautic\ProjectBundle\Model\ProjectModel;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;

class MauticImportProject implements ImportProject
{
    private const NAME_LIMIT = 191;

    /**
     * Build the project handler.
     *
     * @param ConnectorSettings      $settings      Plugin settings
     * @param ProjectModel           $projectModel  Mautic project model
     * @param EntityManagerInterface $entityManager Entity manager
     * @param ManagerRegistry        $registry      Doctrine registry, to reset a closed entity manager
     * @param ActAsUser              $actAs         Runs a save as the import user
     */
    public function __construct(
        private ConnectorSettings $settings,
        private ProjectModel $projectModel,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
        private ActAsUser $actAs,
    ) {
    }

    /**
     * Check the configured project name.
     *
     * @return void
     *
     * @throws ConnectorConfigurationException When it is empty or too long
     */
    public function check(): void
    {
        $this->name();
    }

    /**
     * Find the import project, or create it as the import user.
     *
     * @param User $user Import user
     *
     * @return int
     *
     * @throws ConnectorConfigurationException When the name is not valid or the project cannot be created
     */
    public function prepare(User $user): int
    {
        return (int) $this->project($user)->getId();
    }

    /**
     * Put the email into the import project when it is not in it yet.
     *
     * @param Email $email     Email being saved
     * @param int   $projectId Project id from prepare()
     *
     * @return void
     */
    public function place(Email $email, int $projectId): void
    {
        $project = $this->entityManager->find(Project::class, $projectId);
        if ($project instanceof Project && !$email->getProjects()->contains($project)) {
            $email->addProject($project);
        }
    }

    /**
     * Import project, created when missing.
     *
     * @param User $user Import user
     *
     * @return Project
     *
     * @throws ConnectorConfigurationException When the name is not valid or the project cannot be created
     */
    private function project(User $user): Project
    {
        $name    = $this->name();
        $project = $this->projectModel->getRepository()->findOneBy(['name' => $name]);
        if ($project instanceof Project) {
            return $project;
        }

        $project = new Project();
        $project->setName($name);

        try {
            $this->actAs->run($user, fn () => $this->projectModel->saveEntity($project));

            return $project;
        } catch (UniqueConstraintViolationException) {
            $this->registry->resetManager();
            $found = $this->projectModel->getRepository()->findOneBy(['name' => $name]);
            if ($found instanceof Project) {
                return $found;
            }

            throw new ConnectorConfigurationException('The import project could not be created.');
        }
    }

    /**
     * Configured project name, checked.
     *
     * @return string
     *
     * @throws ConnectorConfigurationException When it is empty or too long
     */
    private function name(): string
    {
        $name = $this->settings->projectName();
        if ('' === $name || mb_strlen($name) > self::NAME_LIMIT) {
            throw new ConnectorConfigurationException('The import project name must have 1 to 191 characters (rge_studio_connector_project).');
        }

        return $name;
    }
}
