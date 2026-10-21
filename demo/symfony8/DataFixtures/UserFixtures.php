<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Demo user
        $demoUser = new User();
        $demoUser->setEmail('demo@example.com');
        $demoUser->setPassword($this->passwordHasher->hashPassword($demoUser, 'demo123'));
        $manager->persist($demoUser);

        // Admin user
        $adminUser = new User();
        $adminUser->setEmail('admin@example.com');
        $adminUser->setPassword($this->passwordHasher->hashPassword($adminUser, 'admin123'));
        $adminUser->setRoles(['ROLE_ADMIN']);
        $manager->persist($adminUser);

        $manager->flush();
    }
}

