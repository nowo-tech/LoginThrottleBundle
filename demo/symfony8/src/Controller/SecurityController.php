<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\LoginFormType;
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
                'message' => 'This is the main firewall. Configuration: 3 attempts, 10 minutes timeout. Use demo@example.com / demo123 or admin@example.com / admin123',
            ]);

            $this->addFlash('info', [
                'title' => '🧪 Multiple Firewalls Demo',
                'message' => 'This demo includes 3 different firewalls with different throttling settings. Try the API login (5 attempts) or the Admin login (3 attempts, 30 min timeout) from the menu.',
            ]);
        }

        $loginForm = $this->createForm(LoginFormType::class, ['_username' => $lastUsername], [
            'action' => $this->generateUrl('login'),
        ]);

        return $this->render('security/login.html.twig', [
            'login_form' => $loginForm,
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
     */
    #[Route('/logout', name: 'logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
