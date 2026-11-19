<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use Mautic\EmailBundle\Entity\Email;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\RgeStudioConnectorBundle\Import\NoImportProject;
use PHPUnit\Framework\TestCase;

final class NoImportProjectTest extends TestCase
{
    /**
     * Without projects in Mautic nothing is checked, no project is prepared and the email is left as it is.
     *
     * @return void
     */
    public function testDoesNothing(): void
    {
        $project = new NoImportProject();
        $email   = new Email();
        $before  = $email->getChanges();

        $project->check();
        self::assertSame(0, $project->prepare(new User()));
        $project->place($email, 0);

        self::assertSame($before, $email->getChanges());
    }
}
