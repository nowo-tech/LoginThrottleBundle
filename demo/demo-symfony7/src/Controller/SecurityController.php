<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\LoginThrottleBundle\Service\LoginThrottleInfoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    /**
     * Login page.
     *
     * @param AuthenticationUtils $authenticationUtils
     * @param Request             $request
     *
     * @return Response
     */
    #[Route('/', name: 'login')]
    public function login(AuthenticationUtils $authenticationUtils, Request $request, LoginThrottleInfoService $throttleInfoService): Response
    {
        // Redirect authenticated users to home page
        if ($this->getUser()) {
            return $this->redirectToRoute('demo_home');
        }

        // Get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        
        // Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();
        
        // Get login attempt information
        $attemptInfo = null;
        if ($error) {
            // Pass the last username to ensure we can track attempts correctly
            $attemptInfo = $throttleInfoService->getAttemptInfo('main', $request, $lastUsername);
        }
        
        $session = $request->getSession();
        
        // Add informational flash messages about demo credentials (only once per session)
        if (!$session->has('login_info_shown')) {
            $session->set('login_info_shown', true);
            $this->addFlash('info', [
                'title' => '🔐 Main Firewall - Demo Login',
                'message' => 'This is the <strong>main</strong> firewall. Configuration: <strong>3 attempts</strong>, <strong>10 minutes</strong> timeout. Use <strong>demo@example.com</strong> / <strong>demo123</strong> or <strong>admin@example.com</strong> / <strong>admin123</strong>',
            ]);
            
            $this->addFlash('info', [
                'title' => '🧪 Multiple Firewalls Demo',
                'message' => 'This demo includes <strong>3 different firewalls</strong> with different throttling settings. Try <a href="' . $this->generateUrl('api_login_page') . '">API login</a> (5 attempts) or <a href="' . $this->generateUrl('admin_login') . '">Admin login</a> (3 attempts, 30 min timeout).',
            ]);
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'attempt_info' => $attemptInfo,
        ]);
    }

    /**
     * Handles logout (route is configured in security.yaml).
     *
     * This method can be blank - it will never be executed!
     * The logout will be intercepted by the logout key on your firewall.
     *
     * @return void
     */
    #[Route('/logout', name: 'logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}

