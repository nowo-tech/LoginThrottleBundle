<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\LoginThrottleBundle\Service\LoginThrottleInfoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Admin Controller for demonstrating admin firewall login throttling.
 */
class AdminController extends AbstractController
{
    /**
     * Admin login page.
     *
     * @param AuthenticationUtils $authenticationUtils
     * @param Request             $request
     *
     * @return Response
     */
    #[Route('/admin/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authenticationUtils, Request $request, LoginThrottleInfoService $throttleInfoService): Response
    {
        // Redirect authenticated users
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        // Get login attempt information
        $attemptInfo = null;
        if ($error) {
            $attemptInfo = $throttleInfoService->getAttemptInfo('admin', $request);
        }

        $session = $request->getSession();

        if (!$session->has('admin_login_info_shown')) {
            $session->set('admin_login_info_shown', true);
            $this->addFlash('warning', [
                'title' => '🔒 Admin Panel - Strict Throttling',
                'message' => 'Admin panel has very strict throttling: <strong>3 attempts</strong> and <strong>30 minutes</strong> timeout. Use <strong>admin@example.com</strong> / <strong>admin123</strong>',
            ]);
        }

        return $this->render('security/admin_login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'attempt_info' => $attemptInfo,
        ]);
    }

    /**
     * Admin home page.
     *
     * @return Response
     */
    #[Route('/admin/home', name: 'admin_home')]
    public function home(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('demo/admin_home.html.twig', [
            'title' => 'Admin Panel - Login Throttle Demo',
            'message' => 'Welcome to the Admin Panel. This demonstrates the admin firewall with strict throttling settings.',
            'firewall_config' => [
                'name' => 'admin',
                'max_attempts' => 3,
                'timeout' => '30 minutes',
                'storage' => 'database',
            ],
        ]);
    }

    /**
     * Handles admin logout.
     *
     * @return void
     */
    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}

