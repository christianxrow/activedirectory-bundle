<?php
namespace Xrow\ActiveDirectoryBundle\Security\Authentication\Provider;

use Symfony\Component\Security\Core\Authentication\Provider\AuthenticationProviderInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\NonceExpiredException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use eZ\Publish\Core\MVC\Symfony\Security\Authentication\RepositoryAuthenticationProvider;
use eZ\Publish\API\Repository\Repository;
use Assetic\Exception\Exception;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Ldap\LdapClient;
use Xrow\ActiveDirectoryBundle\Adapter\LDAP\Client;
use Xrow\ActiveDirectoryBundle\Security\User\RemoteUserHandlerInterface;
use eZ\Publish\Core\Base\Exceptions\NotFoundException;
use Xrow\ActiveDirectoryBundle\Adapter\ActiveDirectory\User;
use eZ\Publish\Core\MVC\Symfony\Security\InteractiveLoginToken;
use eZ\Publish\Core\MVC\Symfony\Security\UserWrapped;
use eZ\Publish\Core\Repository\Values\User\UserReference;

class ActiveDirectoryProvider extends RepositoryAuthenticationProvider implements AuthenticationProviderInterface
{

    private $userProvider;

    private $userHandler;

    const REMOTEID_PREFIX = "ActiveDirectory";

    /**
     *
     * @var \eZ\Publish\API\Repository\Repository
     */
    private $repository;

    public function setRepository(Repository $repository)
    {
        $this->repository = $repository;
    }

    public function setUserHandler(RemoteUserHandlerInterface $userHandler)
    {
        $this->userHandler = $userHandler;
    }

    public function __construct(UserProviderInterface $userProvider)
    {
        $this->userProvider = $userProvider;
    }

    /**
     *
     * @param UsernamePasswordToken $token            
     * @return mixed|UserInterface
     */
    protected function tryActiveDirectoryImport(UsernamePasswordToken $token)
    {
        $currentUser = $token->getUser();
        
        if ('' === ($presentedUsername = $token->getUsername())) {
            throw new AuthenticationCredentialsNotFoundException('The presented username cannot be empty.');
        }
        
        if ('' === ($presentedPassword = $token->getCredentials())) {
            throw new AuthenticationCredentialsNotFoundException('The presented password cannot be empty.');
        }
        
        $config = [
            // Your account suffix, for example: jdoe@corp.acme.org
            'account_suffix' => '@xrow.lan',
            
            // The domain controllers option is an array of your LDAP hosts. You can
            // use the either the host name or the IP address of your host.
            'domain_controllers' => [
                'dc01.xrow.lan',
                '192.168.0.220'
            ],
            
            // The base distinguished name of your domain.
            'base_dn' => 'dc=XROW,dc=LAN'
        ];
        $client = new \Adldap\Adldap();
        $client->addProvider($config);
        
        $this->client = new Client($client);
        
        // communication errors and config errors should be logged/handled by the client
        try {
            $user = $this->client->authenticateUser($presentedUsername, $presentedPassword);
        } catch (\Exception $e) {
            throw new BadCredentialsException('The presented username or password is invalid.');
        }
        
        try {
            $apiUser = $this->repository->getUserService()->loadUserByLogin($presentedUsername . '@xrow.lan');
        } catch (\Exception $e) {
             $RepoUser = $this->userHandler->createRepoUser($user);
             return new UserWrapped ( new User($user, $presentedUsername, $presentedPassword), $RepoUser);
        }
        $RepoUser = $this->userHandler->updateRepoUser($user, $apiUser);
        return new UserWrapped( new User($user, $presentedUsername, $presentedPassword), $RepoUser );
    }

    /**
     * Copied from UserAuthenticationProvider
     */
    protected function getRoles(UserInterface $user, TokenInterface $token)
    {
        $roles = $user->getRoles();
        
        foreach ($token->getRoles() as $role) {
            if ($role instanceof SwitchUserRole) {
                $roles[] = $role;
                
                break;
            }
        }
        
        return $roles;
    }

    private function isValidActiveDirectoryUser($apiUser)
    {
        $remoteid = $apiUser->getVersionInfo()->getContentInfo()->remoteId;
        preg_match('@^(ActiveDirectory):(.+)@i', $remoteid, $test);
        if (isset($test[1]) and $test[1] === "ActiveDirectory") {
            return true;
        }
    }

    public function authenticate(TokenInterface $token)
    {
        // $currentUser can either be an instance of UserInterface or just the username (e.g. during form login).
        /** @var EzUserInterface|string $currentUser */
        $currentUser = $token->getUser();
        if ($currentUser instanceof UserInterface) {
            return $currentUser;
        }

        try {
            $UserNative = $this->repository->getUserService()->loadUserByLogin($token->getUsername());
        } catch (NotFoundException $e) {
            $UserNative = false;
        }
        try {
            $ADUser = $this->repository->getUserService()->loadUserByLogin($token->getUsername() . '@xrow.lan');
        } catch (NotFoundException $e) {
            $ADUser = false;
        } catch (\Exception $e) {
            $ADUser = false;
        }
        $apiUser = false;
        if ($ADUser and $this->isValidActiveDirectoryUser($ADUser)) {
            try {
                $UserWrapped = $this->tryActiveDirectoryImport($token);
            } catch (\Exception $e) {
                throw new BadCredentialsException('Invalid directory user', 0, $e);
            }
        } else {
            try {
                $UserWrapped = $this->tryActiveDirectoryImport($token);
            } catch (\Exception $e) {
                if (! $UserNative) {
                    throw new BadCredentialsException('Invalid directory user', 0, $e);
                }
                // go on with native users
            }
        }
        // Try normal login
        if (! $apiUser and $UserNative) {
            try {
                $apiUser = $this->repository->getUserService()->loadUserByCredentials($token->getUsername(), $token->getCredentials());
                $UserWrapped = new UserWrapped( $apiUser, $UserNative);
                
            } catch (\Exception $e) {
                throw new BadCredentialsException('Invalid credentials', 0, $e);
            }
        }
        // Can`t find the user anywhere
        if (! $UserWrapped ) {
            throw new UsernameNotFoundException('Invalid directory user', 0, $e);
        }
        
        if ($currentUser instanceof UserInterface) {
            if ($currentUser->getAPIUser()->passwordHash !== $user->getAPIUser()->passwordHash) {
                throw new BadCredentialsException('The credentials were changed from another session.');
            }
            $apiUser = $currentUser->getAPIUser();
        }
        
        // Finally inject current user
        $permissionResolver = $this->repository->getPermissionResolver();
        $UserReference = new UserReference( $UserWrapped->getAPIUser()->id );
        $permissionResolver->setCurrentUserReference( $UserReference );

        $providerKey = method_exists($token, 'getProviderKey') ? $token->getProviderKey() : __CLASS__;
        $interactiveToken = new InteractiveLoginToken(
            $UserWrapped,
            get_class($token),
            $token->getCredentials(),
            $providerKey,
            $token->getRoles()
            );
        $interactiveToken->setAttributes($token->getAttributes());
        
        return $interactiveToken;
    }

    public function supports(TokenInterface $token)
    {
        return $token instanceof UsernamePasswordToken;
    }
}