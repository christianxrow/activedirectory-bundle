<?php
namespace Xrow\ActiveDirectoryBundle\EventListener;

use eZ\Publish\API\Repository\UserService;
use eZ\Publish\Core\MVC\Symfony\Event\InteractiveLoginEvent as EZInteractiveLoginEvent;
use eZ\Publish\Core\MVC\Symfony\MVCEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Assetic\Exception\Exception;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

class InteractiveLoginListener implements EventSubscriberInterface
{
    /**
     * @var \eZ\Publish\API\Repository\UserService
     */
    private $userService;
    
    public function __construct( UserService $userService )
    {
        $this->userService = $userService;
    }
    
    public static function getSubscribedEvents()
    {
        return array(
            MVCEvents::INTERACTIVE_LOGIN => 'onInteractiveLogin',
            SecurityEvents::INTERACTIVE_LOGIN => 'onInteractiveLoginSymfony'
        );
    }
    public function onInteractiveLoginSymfony( InteractiveLoginEvent $event )
    {
        var_dump($event->getAuthenticationToken());
    }
    public function onInteractiveLogin( InteractiveLoginEvent $event )
    {
        var_dump($event->getAuthenticationToken());
    }
} 