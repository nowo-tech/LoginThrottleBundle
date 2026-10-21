<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ApiLoginFormType;
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
     * API login endpoint (matches check_path in security.yaml).
     * This route is used by json_login authenticator.
     *
     * @return JsonResponse
     */
    #[Route('/api/login', name: 'api_login')]
    public function login(): JsonResponse
    {
        // json_login authenticates the request and lets it reach this action on success (no success_handler);
        // failures are answered by the authenticator itself.
        $user = $this->getUser();
        if (null === $user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return new JsonResponse(['user' => $user->getUserIdentifier()]);
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
            'login_form' => $this->createForm(ApiLoginFormType::class),
            'title' => 'API Login',
        ]);
    }
}

