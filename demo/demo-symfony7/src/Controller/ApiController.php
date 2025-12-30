<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API Controller for demonstrating API firewall login throttling.
 */
class ApiController extends AbstractController
{
    /**
     * API home endpoint.
     *
     * @return JsonResponse
     */
    #[Route('/api/home', name: 'api_home')]
    public function home(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return new JsonResponse([
            'message' => 'Welcome to the API',
            'user' => [
                'email' => $this->getUser()?->getUserIdentifier(),
                'roles' => $this->getUser()?->getRoles(),
            ],
            'firewall' => 'api',
            'throttling_config' => [
                'max_attempts' => 5,
                'timeout' => '5 minutes',
                'storage' => 'database',
            ],
        ]);
    }

    /**
     * API login page (for testing).
     *
     * @param Request $request
     *
     * @return Response
     */
    #[Route('/api/login-page', name: 'api_login_page')]
    public function loginPage(Request $request): Response
    {
        // Redirect authenticated users
        if ($this->getUser()) {
            return $this->redirectToRoute('api_home');
        }

        return $this->render('security/api_login.html.twig', [
            'title' => 'API Login',
        ]);
    }
}

