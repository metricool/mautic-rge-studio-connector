<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Functional\Import;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\ProjectBundle\Entity\Project;
use Mautic\ProjectBundle\Model\ProjectModel;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\RgeStudioConnectorBundle\Import\ActAsUser;
use MauticPlugin\RgeStudioConnectorBundle\Import\MauticImportProject;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SettingsStub;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class MauticImportProjectTest extends MauticMysqlTestCase
{
    /**
     * When another import creates the project between the lookup and the save, its project is used.
     *
     * @return void
     */
    public function testProjectCreatedMeanwhileIsUsed(): void
    {
        if (!class_exists(ProjectModel::class)) {
            self::markTestSkipped('This Mautic has no projects.');
        }

        $other = new Project();
        $other->setName('Another project');
        $this->em->persist($other);
        $this->em->flush();

        $table    = $this->em->getClassMetadata(Project::class)->getTableName();
        $listener = new class($this->em->getConnection(), $table) {
            public int $id = 0;

            /**
             * Build the listener.
             *
             * @param Connection $connection Database connection
             * @param string     $table      Projects table
             */
            public function __construct(private Connection $connection, private string $table)
            {
            }

            /**
             * Insert a project of the same name just before the import saves its own.
             *
             * @param PrePersistEventArgs $args Event arguments
             *
             * @return void
             */
            public function prePersist(PrePersistEventArgs $args): void
            {
                $project = $args->getObject();
                if ($project instanceof Project && 0 === $this->id) {
                    $this->connection->insert($this->table, ['is_published' => 0, 'name' => $project->getName(), 'properties' => '[]']);
                    $this->id = (int) $this->connection->lastInsertId();
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::prePersist], $listener);

        $container = static::getContainer();
        $project   = new MauticImportProject(
            SettingsStub::create([]),
            $container->get(ProjectModel::class),
            $this->em,
            $container->get(ManagerRegistry::class),
            new ActAsUser($container->get(TokenStorageInterface::class))
        );
        $admin = $this->em->find(User::class, 1);
        self::assertInstanceOf(User::class, $admin);

        $id = $project->prepare($admin);

        self::assertNotSame(0, $listener->id);
        self::assertSame($listener->id, $id);
    }
}
