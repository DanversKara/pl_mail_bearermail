<?php

namespace App\Controller\Auth;

use App\Entity\User\User;
use App\EventSubscriber\SsoSignedOutCookie;
use App\Service\Oidc\OidcSettings;
use App\Service\Setup\InstallGuard;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    private AuthorizationCheckerInterface $authorizationChecker;
    private RouterInterface $router;

    public function __construct(AuthorizationCheckerInterface $authorizationChecker, RouterInterface $router)
    {
        $this->authorizationChecker = $authorizationChecker;
        $this->router = $router;
    }


    #[Route('/login', 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, InstallGuard $installGuard, Request $request, OidcSettings $oidcSettings): Response
    {
        if (true === $this->authorizationChecker->isGranted(User::ROLE_USER)) {
            return new RedirectResponse($this->router->generate('app_default_index'));
        }

        // A signed-out visitor is already being sent here, so this is the one
        // place worth spending a count query to notice that the install has no
        // users yet. Nothing else in the request cycle checks, which is why
        // there is no global listener costing every page a query.
        if (true === $installGuard->isAvailable()) {
            return new RedirectResponse($this->router->generate('app_install'));
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        // BearerMail: "go straight to the provider". Never after an error (a
        // provider that is down would otherwise bounce the visitor in a loop),
        // never just after signing out (the provider's own session would sign
        // them straight back in), and never when ?password=1 asks for the form.
        $oidc = $oidcSettings->current();
        if (true === $oidc->isEnabled()
            && true === $oidc->straightToProvider
            && null === $error
            && false === $request->query->has('password')
            && false === $request->cookies->has(SsoSignedOutCookie::NAME)
        ) {
            return new RedirectResponse($this->router->generate('app_login_oidc'));
        }

        return $this->render('security/login.html.twig', ['last_username' => $lastUsername, 'error' => $error]);
    }

    #[Route('/logout', 'app_logout')]
    public function logout()
    {
        throw new LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
