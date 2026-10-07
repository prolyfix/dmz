<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SynstituteInstance;
use App\Repository\SynstituteInstanceRepository;
use App\Service\BookingTargetUrlValidator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly SynstituteInstanceRepository $instanceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingTargetUrlValidator $targetUrlValidator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/login', name: 'dmz_admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('dmz_admin_instances');
        }

        $error = null === $authenticationUtils->getLastAuthenticationError()
            ? ''
            : '<p role="alert">Connexion impossible. Verifiez vos identifiants ou reessayez plus tard.</p>';

        return $this->page('Administration des instances', $error . sprintf(
            '<form action="%s" method="post">
                <input type="hidden" name="_csrf_token" value="%s">
                <p><label>Identifiant <input name="_username" value="%s" autocomplete="username" required></label></p>
                <p><label>Mot de passe <input type="password" name="_password" autocomplete="current-password" required></label></p>
                <button type="submit">Se connecter</button>
            </form>',
            $this->e($this->generateUrl('dmz_admin_login')),
            $this->token('authenticate'),
            $this->e($authenticationUtils->getLastUsername()),
        ));
    }

    #[Route('/logout', name: 'dmz_admin_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Logout is handled by the administrator firewall.');
    }

    #[Route('', name: 'dmz_admin_instances', methods: ['GET'])]
    public function instances(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $messages = '';
        foreach ($request->getSession()->getFlashBag()->get('admin_success') as $message) {
            $messages .= '<p role="status">' . $this->e($message) . '</p>';
        }

        return $this->instancePage($messages);
    }

    #[Route('/instances', name: 'dmz_admin_instance_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->requireToken($request, 'instance_create');

        $identifier = trim($request->request->getString('identifier'));
        $targetUrl = trim($request->request->getString('bookingTargetUrl'));
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/D', $identifier)) {
            return $this->instancePage('<p role="alert">Identifiant invalide : utilisez 3 a 64 lettres, chiffres, points, tirets ou underscores.</p>', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->targetUrlValidator->isAllowed($targetUrl)) {
            return $this->invalidTargetResponse();
        }
        if (null !== $this->instanceRepository->findOneBy(['identifier' => $identifier])) {
            return $this->instancePage('<p role="alert">Cet identifiant existe deja.</p>', Response::HTTP_CONFLICT);
        }

        $apiKey = $this->newApiKey();
        $instance = (new SynstituteInstance())
            ->setIdentifier($identifier)
            ->setBookingTargetUrl($targetUrl)
            ->setApiKeyHash(password_hash($apiKey, PASSWORD_DEFAULT))
            ->setIsActive(true)
            ->setRequireHttps(true);
        $this->entityManager->persist($instance);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            $this->logger->warning('Concurrent instance creation rejected.', ['identifier' => $identifier, 'exception' => $exception]);

            return $this->page('Creation impossible', '<p role="alert">Cet identifiant existe deja. Rechargez la liste des instances.</p>', Response::HTTP_CONFLICT);
        }
        $this->csrfTokenManager->removeToken('instance_create');
        $this->audit('created', $instance);

        return $this->keyPage($instance, $apiKey, Response::HTTP_CREATED);
    }

    #[Route('/instances/{id}/update', name: 'dmz_admin_instance_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->requireToken($request, 'instance_update_' . $id);
        $instance = $this->requireInstance($id);
        $targetUrl = trim($request->request->getString('bookingTargetUrl'));
        if (!$this->targetUrlValidator->isAllowed($targetUrl)) {
            return $this->invalidTargetResponse();
        }

        $instance->setBookingTargetUrl($targetUrl);
        $this->entityManager->flush();
        $this->audit('target_updated', $instance);

        return $this->successRedirect('URL cible mise a jour.');
    }

    #[Route('/instances/{id}/status', name: 'dmz_admin_instance_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(Request $request, int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->requireToken($request, 'instance_status_' . $id);
        $active = $request->request->getString('active');
        if (!in_array($active, ['0', '1'], true)) {
            throw new BadRequestHttpException('Invalid instance status.');
        }
        $instance = $this->requireInstance($id);
        $instance->setIsActive('1' === $active);
        $this->entityManager->flush();
        $this->audit($instance->isActive() ? 'activated' : 'deactivated', $instance);

        return $this->successRedirect('Statut de l\'instance mis a jour.');
    }

    #[Route('/instances/{id}/rotate-key', name: 'dmz_admin_instance_rotate_key', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rotateKey(Request $request, int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->requireToken($request, 'instance_rotate_' . $id);
        if ('1' !== $request->request->getString('confirm')) {
            return $this->instancePage('<p role="alert">Confirmez le remplacement de la cle API.</p>', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $instance = $this->requireInstance($id);
        $apiKey = $this->newApiKey();
        $instance->setApiKeyHash(password_hash($apiKey, PASSWORD_DEFAULT));
        $this->entityManager->flush();
        $this->csrfTokenManager->removeToken('instance_rotate_' . $id);
        $this->audit('key_rotated', $instance);

        return $this->keyPage($instance, $apiKey);
    }

    private function instancePage(string $message = '', int $status = Response::HTTP_OK): Response
    {
        $body = $message . '<p>Gestion des instances uniquement. Aucune reservation ni donnee client n\'est affichee.</p>';
        $body .= sprintf(
            '<form action="%s" method="post"><input type="hidden" name="_csrf_token" value="%s"><button>Se deconnecter</button></form>
            <h2>Creer une instance</h2>
            <form action="%s" method="post">
                <input type="hidden" name="_csrf_token" value="%s">
                <p><label>Identifiant <input name="identifier" minlength="3" maxlength="64" pattern="[A-Za-z0-9._-]{3,64}" required></label></p>
                <p><label>URL cible HTTPS <input type="url" name="bookingTargetUrl" maxlength="255" required></label></p>
                <button>Creer et generer la cle API</button>
            </form><h2>Instances</h2>',
            $this->e($this->generateUrl('dmz_admin_logout')),
            $this->token('logout'),
            $this->e($this->generateUrl('dmz_admin_instance_create')),
            $this->token('instance_create'),
        );

        foreach ($this->instanceRepository->findBy([], ['identifier' => 'ASC']) as $instance) {
            $id = $instance->getId();
            $body .= sprintf(
                '<section><h3>%s</h3><p>ID : %d - %s</p>
                <form action="%s" method="post">
                    <input type="hidden" name="_csrf_token" value="%s">
                    <label>URL cible HTTPS <input type="url" name="bookingTargetUrl" value="%s" maxlength="255" required></label>
                    <button>Enregistrer l\'URL</button>
                </form>
                <form action="%s" method="post">
                    <input type="hidden" name="_csrf_token" value="%s">
                    <input type="hidden" name="active" value="%s">
                    <button>%s</button>
                </form>
                <form action="%s" method="post">
                    <input type="hidden" name="_csrf_token" value="%s">
                    <label><input type="checkbox" name="confirm" value="1" required> Je confirme : l\'ancienne cle cessera de fonctionner.</label>
                    <button>Renouveler la cle API</button>
                </form></section>',
                $this->e($instance->getIdentifier()),
                $id,
                $instance->isActive() ? 'Active' : 'Inactive',
                $this->e($this->generateUrl('dmz_admin_instance_update', ['id' => $id])),
                $this->token('instance_update_' . $id),
                $this->e($instance->getBookingTargetUrl()),
                $this->e($this->generateUrl('dmz_admin_instance_status', ['id' => $id])),
                $this->token('instance_status_' . $id),
                $instance->isActive() ? '0' : '1',
                $instance->isActive() ? 'Desactiver' : 'Activer',
                $this->e($this->generateUrl('dmz_admin_instance_rotate_key', ['id' => $id])),
                $this->token('instance_rotate_' . $id),
            );
        }

        return $this->page('Instances Synstitute', $body, $status);
    }

    private function invalidTargetResponse(): Response
    {
        return $this->instancePage('<p role="alert">URL refusee : utilisez une URL HTTPS publique (port 443), sans identifiants ni fragment. Son nom DNS doit etre resolvable.</p>', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function keyPage(SynstituteInstance $instance, string $apiKey, int $status = Response::HTTP_OK): Response
    {
        return $this->page('Cle API generee', sprintf(
            '<p>Instance : <strong>%s</strong></p>
            <p>Enregistrez cette cle dans un endroit sur. Elle ne sera pas affichee dans la liste des instances. Ne rechargez pas cette page.</p>
            <p><code>%s</code></p><p><a href="%s">Retour aux instances</a></p>',
            $this->e($instance->getIdentifier()),
            $this->e($apiKey),
            $this->e($this->generateUrl('dmz_admin_instances')),
        ), $status);
    }

    private function requireInstance(int $id): SynstituteInstance
    {
        $instance = $this->instanceRepository->find($id);
        if (null === $instance) {
            throw $this->createNotFoundException('Instance not found.');
        }

        return $instance;
    }

    private function requireToken(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function successRedirect(string $message): Response
    {
        $this->addFlash('admin_success', $message);

        return $this->redirectToRoute('dmz_admin_instances', [], Response::HTTP_SEE_OTHER);
    }

    private function token(string $id): string
    {
        return $this->e($this->csrfTokenManager->getToken($id)->getValue());
    }

    private function newApiKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function audit(string $action, SynstituteInstance $instance): void
    {
        $this->logger->info('Synstitute instance administration.', [
            'action' => $action,
            'instance' => $instance->getIdentifier(),
            'administrator' => $this->getUser()?->getUserIdentifier(),
        ]);
    }

    private function page(string $title, string $body, int $status = Response::HTTP_OK): Response
    {
        $title = $this->e($title);
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="fr">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <style>body{font-family:system-ui,sans-serif;max-width:960px;margin:2rem auto;padding:0 1rem}section{border:1px solid #ccc;padding:1rem;margin:1rem 0}form{margin:1rem 0}input[type=url]{width:min(100%,36rem)}input,button{padding:.4rem}code{overflow-wrap:anywhere}[role=alert]{color:#a00}</style></head>
            <body><h1>{$title}</h1>{$body}</body></html>
            HTML;

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
