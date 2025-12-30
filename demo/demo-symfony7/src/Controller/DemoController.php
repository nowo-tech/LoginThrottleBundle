<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DemoController extends AbstractController
{
    #[Route('/home', name: 'demo_home')]
    public function home(Request $request): Response
    {
        $session = $request->getSession();
        
        // Add informational flash messages (only once per session)
        if (!$session->has('demo_info_shown')) {
            $session->set('demo_info_shown', true);
            $this->addFlash('info', [
                'title' => '🧪 Login Throttle Bundle Demo',
                'message' => 'This demo showcases the Login Throttle Bundle functionality. Try logging in with wrong credentials multiple times to see throttling in action!',
            ]);
        }
        
        return $this->render('demo/home.html.twig', [
            'title' => 'Login Throttle Bundle - Demo',
            'message' => 'This demo showcases the Login Throttle Bundle with <strong>multiple firewalls</strong> and different throttling configurations.',
            'features' => [
                'Multiple firewalls with independent throttling settings',
                'Main firewall: 3 attempts, 10 minutes (cache storage)',
                'API firewall: 5 attempts, 5 minutes (database storage)',
                'Admin firewall: 3 attempts, 30 minutes (database storage)',
                'Native Symfony login_throttling integration',
                'Protection against brute force attacks',
                'Running on Symfony 7.0',
            ],
            'firewalls' => [
                [
                    'name' => 'Main',
                    'path' => $this->generateUrl('login'),
                    'max_attempts' => 3,
                    'timeout' => '10 minutes',
                    'storage' => 'database',
                ],
                [
                    'name' => 'API',
                    'path' => $this->generateUrl('api_login_page'),
                    'max_attempts' => 5,
                    'timeout' => '5 minutes',
                    'storage' => 'database',
                ],
                [
                    'name' => 'Admin',
                    'path' => $this->generateUrl('admin_login'),
                    'max_attempts' => 3,
                    'timeout' => '30 minutes',
                    'storage' => 'database',
                ],
            ],
        ]);
    }
}

