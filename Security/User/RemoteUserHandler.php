<?php
namespace Xrow\ActiveDirectoryBundle\Security\User;

use eZ\Publish\API\Repository\Repository;
use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\User\User;
use eZ\Publish\API\Repository\Exceptions\NotFoundException;
use Adldap\Models\User as ActiveDirectoryUser;
use eZ\Publish\Core\Repository\Permission\PermissionResolver;
use Symfony\Component\Validator\Constraints\DateTime;

/**
 * A 'generic' Remote user handler class.
 *
 * For the common cases, you will need to implement only getGroupsFromUser() and setFieldValuesFromProfile().
 * But you can subclass more methods for more complex scenarios :-)
 *
 * It handles
 * - multiple user groups assignments per user
 * - automatic update of the ez user when its ldap profile has changed compared to the stored one
 */
class RemoteUserHandler implements RemoteUserHandlerInterface
{

    protected $repository;

    protected $settings;

    protected $tempFiles = array();

    const REMOTEID_PREFIX = 'ActiveDirectory:';

    /**
     *
     * @param Repository $repository            
     * @param array $settings            
     */
    public function __construct(Repository $repository, array $settings)
    {
        $this->repository = $repository;
        $this->settings = $settings;
    }

    /**
     * Returns the API user corresponding to a given remoteUser (if it exists), or false.
     *
     * @see \eZ\Publish\Core\MVC\Symfony\Security\User\Provider::loadUserByUsername()
     *
     * @param RemoteUser $remoteUser            
     * @return \eZ\Publish\API\Repository\Values\User\User|false
     */
    public function loadAPIUserByRemoteUser(ActiveDirectoryUser $remoteUser)
    {
        try {
            return $this->repository->getUserService()->loadUserByLogin($remoteUser->getUsername());
        } catch (NotFoundException $e) {
            return false;
        }
    }

    /**
     *
     * @param Adldap\Models\User $user          
     * @return \eZ\Publish\API\Repository\Values\User\User
     */
    public function createRepoUser(ActiveDirectoryUser $user)
    {
        return $this->repository->sudo(function () use ($user) {
            // / @todo support creating users using a different user account
            // $this->repository->setCurrentUser($userService->loadUser($this->settings['user_creator']));
            
            $userService = $this->repository->getUserService();

            // the user passwords we do not store locally
            $userCreateStruct = $userService->newUserCreateStruct(
                // is 128 bytes enough for everyone? (pun intended)
                $user->getUserPrincipalName(), $user->getEmail(), bin2hex(random_bytes(128)), $this->settings['default_content_language'], $this->repository->getContentTypeService()
                    ->loadContentTypeByIdentifier("user"));
            
            $this->setFieldValuesFromUser($user, $userCreateStruct);
            
            $userCreateStruct->remoteId = self::REMOTEID_PREFIX . $user->getAuthIdentifier();
            
            $userGroups = $this->getGroupsFromUser($user);

            $repoUser = $userService->createUser($userCreateStruct, $userGroups);
   
                        
            return $repoUser;
        });
    }

    /**
     *
     * @param Adldap\Models\User $user            
     * @param eZ\Publish\API\Repository\Values\User\User $eZUser
     */
    public function updateRepoUser(ActiveDirectoryUser $user, $eZUser)
    {
        if ($this->localUserNeedsUpdating($user, $eZUser)) {
            return $this->repository->sudo(function () use ($user, $eZUser) {
                $userService = $this->repository->getUserService();
                $contentService = $this->repository->getContentService();
                
                $userUpdateStruct = $userService->newUserUpdateStruct();
                $contentUpdateStruct = $contentService->newContentUpdateStruct();
                $this->setFieldValuesFromUser($user, $contentUpdateStruct);
                $userUpdateStruct->contentUpdateStruct = $contentUpdateStruct;
       
                // we use a transaction since there are multiple db operations
                try {
                    $this->repository->beginTransaction();
                    
                    $repoUser = $userService->updateUser($eZUser, $userUpdateStruct);

                    // fix user groups assignments: first add new ones, then remove unused current ones (we can not hit 0 groups during the updating :-) )
                    // / @todo test/document what happens when we get an empty array...
                    $newUserGroups = $this->getGroupsFromUser($user);
                    $currentUserGroups = $userService->loadUserGroupsOfUser($eZUser);
                    $groupsToRemove = array();
                    var_dump($newUserGroups);
                    var_dump($currentUserGroups);die("here");
                    foreach ($currentUserGroups as $currentUserGroup) {
                        if (! array_key_exists($currentUserGroup->id, $newUserGroups)) {
                            $groupsToRemove[] = $currentUserGroup;
                        } else {
                            unset($newUserGroups[$currentUserGroup->id]);
                        }
                    }
                    foreach ($newUserGroups as $newUserGroup) {
                        $userService->assignUserToUserGroup($repoUser, $newUserGroup);
                    }
                    foreach ($groupsToRemove as $groupToRemove) {
                        $userService->unAssignUserFromUserGroup($repoUser, $groupToRemove);
                    }
                    
                    $this->repository->commit();
                } catch (\Exception $e) {
                    $this->repository->rollback();
                    throw $e;
                }                
                return $repoUser;
            });
        }
    }

    /**
     * Load (and possibly create on the fly) all the user groups needed for this user, based on his profile.
     *
     * @param ActiveDirectoryUser $user
     *            
     * @return \eZ\Publish\API\Repository\Values\User\UserGroup[] indexed by group id
     */
    public function getGroupsFromUser(ActiveDirectoryUser $user)
    {
        $userService = $this->repository->getUserService();
        $groups = array();
        $list = $user->getGroups();
        foreach ( $list as $group) {
            $ezgroup = $this->createGroupIfNotExists($group);            
            $groups[$ezgroup->id] = $userService->loadUserGroup($ezgroup->mainLocationId);;
        }
        return $groups;
    }
    /**
     * Load (and possibly create on the fly) the user group needed
     *
     * \Adldap\Models\Group $group
     *
     * @return \eZ\Publish\API\Repository\Values\User\UserGroup[] indexed by group id
     */
    private function createGroupIfNotExists(\Adldap\Models\Group $group)
    {
        $searchService = $this->repository->getSearchService();
        $locationService = $this->repository->getLocationService();
        
        // create the query with three criteria
        $query = new \eZ\Publish\API\Repository\Values\Content\Query();
        $criterion1 = new Criterion\Subtree($locationService->loadLocation(5)->pathString);
        $criterion2 = new Criterion\ContentTypeIdentifier("user_group");
        $criterion3 = new Criterion\RemoteId(self::REMOTEID_PREFIX . $group->getDistinguishedName());
        $query->filter = new Criterion\LogicalAnd(array(
            $criterion1,
            $criterion2,
            $criterion3
        ));
        $result = $searchService->findContent($query);
        if ($result->totalCount === 0) {
            $contentService = $this->repository->getContentService();
            $locationService = $this->repository->getLocationService();
            $contentTypeService = $this->repository->getContentTypeService();
            PermissionResolver::setCurrentUserReference($this->repository->getUserService()->loadUser(14));
            $contentType = $contentTypeService->loadContentTypeByIdentifier("user_group");
            $contentCreateStruct = $contentService->newContentCreateStruct($contentType, 'eng-GB');
            $contentCreateStruct->setField('name', $group->getName());
            $contentCreateStruct->remoteId = self::REMOTEID_PREFIX . $group->getDistinguishedName();
            // instantiate a location create struct from the parent location
            $locationCreateStruct = $locationService->newLocationCreateStruct(5);
            $locationCreateStruct->remoteId = self::REMOTEID_PREFIX . $group->getDistinguishedName();
            // create a draft using the content and location create struct and publish it
            $draft = $contentService->createContent($contentCreateStruct, array(
                $locationCreateStruct
            ));
            $content = $contentService->publishVersion($draft->versionInfo);
        } elseif ($result->totalCount === 1) {
            return $result->searchHits[0]->valueObject->contentInfo;
        }
    }

    /**
     *
     * @param \Adldap\Models\User  $user
     * @param \eZ\Publish\API\Repository\Values\Content\ContentCreateStruct $userCreateStruct            
     *
     * @todo allow to define simple field mappings in settings
     */
    public function setFieldValuesFromUser( \Adldap\Models\User $user, $userCreateStruct)
    {
        $userCreateStruct->setField('first_name',  $user->getFirstName());
        $userCreateStruct->setField('last_name',  $user->getLastName());
    }

    /**
     * Checks if the local user profile needs updating compared to the remote user profile
     *
     * @param RemoteUser $remoteUser            
     * @param $eZUser (is
     *            this an eZ\Publish\API\Repository\Values\User\User ?)
     * @return bool
     */
    protected function localUserNeedsUpdating(\Adldap\Models\User $user, $eZUser)
    {
        return true;
    }

}