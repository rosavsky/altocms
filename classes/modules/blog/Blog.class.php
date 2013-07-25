<?php
/*---------------------------------------------------------------------------
 * @Project: Alto CMS
 * @Project URI: http://altocms.com
 * @Description: Advanced Community Engine
 * @Copyright: Alto CMS Team
 * @License: GNU GPL v2 & MIT
 *----------------------------------------------------------------------------
 * Based on
 *   LiveStreet Engine Social Networking by Mzhelskiy Maxim
 *   Site: www.livestreet.ru
 *   E-mail: rus.engine@gmail.com
 *----------------------------------------------------------------------------
 */

/**
 * Модуль для работы с блогами
 *
 * @package modules.blog
 * @since   1.0
 */
class ModuleBlog extends Module {
    /**
     * Возможные роли пользователя в блоге
     */
    const BLOG_USER_ROLE_GUEST = 0;
    const BLOG_USER_ROLE_MEMBER = 1;
    const BLOG_USER_ROLE_MODERATOR = 2;
    const BLOG_USER_ROLE_ADMINISTRATOR = 4;
    const BLOG_USER_ROLE_OWNER = 8;
    const BLOG_USER_ROLE_NOTMEMBER = 16;

    // LS-compatible //
    const BLOG_USER_ROLE_USER = 1;

    const BLOG_USER_JOIN_NONE = 0;
    const BLOG_USER_JOIN_FREE = 1;
    const BLOG_USER_JOIN_REQUEST = 2;
    const BLOG_USER_JOIN_INVITE = 4;

    const BLOG_USER_ACL_GUEST = 1;
    const BLOG_USER_ACL_USER = 2;
    const BLOG_USER_ACL_MEMBER = 4;

    /**
     * Пользователь, приглашенный админом блога в блог
     */
    const BLOG_USER_ROLE_INVITE = -1;

    /**
     * Пользователь, отклонивший приглашение админа
     */
    const BLOG_USER_ROLE_REJECT = -2;
    /**
     * Забаненный в блоге пользователь
     */
    const BLOG_USER_ROLE_BAN = -4;

    /**
     * Объект маппера
     *
     * @var ModuleBlog_MapperBlog
     */
    protected $oMapper;

    /** @var  LS-compatible */
    protected $oMapperBlog;
    /**
     * Объект текущего пользователя
     *
     * @var ModuleUser_EntityUser|null
     */
    protected $oUserCurrent = null;

    /**
     * Инициализация
     *
     */
    public function Init() {

        $this->oMapper = Engine::GetMapper(__CLASS__);
        $this->oUserCurrent = $this->User_GetUserCurrent();

        /**
         * LS-compatible
         */
        $this->oMapperBlog = $this->oMapper;
    }

    /**
     * Получает дополнительные данные(объекты) для блогов по их ID
     *
     * @param array|int $aBlogId       Список ID блогов
     * @param array     $aAllowData    Список типов дополнительных данных, которые нужно получить для блогов
     * @param array     $aOrder        Порядок сортировки
     *
     * @return array
     */
    public function GetBlogsAdditionalData($aBlogId, $aAllowData = null, $aOrder = null) {

        if (is_null($aAllowData)) {
            $aAllowData = array('vote', 'owner' => array(), 'relation_user');
        }
        $aAllowData = F::Array_FlipIntKeys($aAllowData);
        if (!is_array($aBlogId)) {
            $aBlogId = array($aBlogId);
        }
        /**
         * Получаем блоги
         */
        $aBlogs = $this->GetBlogsByArrayId($aBlogId, $aOrder);
        /**
         * Формируем ID дополнительных данных, которые нужно получить
         */
        $aUserId = array();
        foreach ($aBlogs as $oBlog) {
            if (isset($aAllowData['owner'])) {
                $aUserId[] = $oBlog->getOwnerId();
            }
        }
        /**
         * Получаем дополнительные данные
         */
        $aBlogUsers = array();
        $aBlogsVote = array();
        $aUsers = (isset($aAllowData['owner']) && is_array($aAllowData['owner']))
            ? $this->User_GetUsersAdditionalData($aUserId, $aAllowData['owner'])
            : $this->User_GetUsersAdditionalData($aUserId);
        if (isset($aAllowData['relation_user']) && $this->oUserCurrent) {
            $aBlogUsers = $this->GetBlogUsersByArrayBlog($aBlogId, $this->oUserCurrent->getId());
        }
        if (isset($aAllowData['vote']) && $this->oUserCurrent) {
            $aBlogsVote = $this->Vote_GetVoteByArray($aBlogId, 'blog', $this->oUserCurrent->getId());
        }
        /**
         * Добавляем данные к результату - списку блогов
         */
        foreach ($aBlogs as $oBlog) {
            if (isset($aUsers[$oBlog->getOwnerId()])) {
                $oBlog->setOwner($aUsers[$oBlog->getOwnerId()]);
            } else {
                $oBlog->setOwner(null); // или $oBlog->setOwner(new ModuleUser_EntityUser());
            }
            if (isset($aBlogUsers[$oBlog->getId()])) {
                $oBlog->setUserIsJoin(true);
                $oBlog->setUserIsAdministrator($aBlogUsers[$oBlog->getId()]->getIsAdministrator());
                $oBlog->setUserIsModerator($aBlogUsers[$oBlog->getId()]->getIsModerator());
            } else {
                $oBlog->setUserIsJoin(false);
                $oBlog->setUserIsAdministrator(false);
                $oBlog->setUserIsModerator(false);
            }
            if (isset($aBlogsVote[$oBlog->getId()])) {
                $oBlog->setVote($aBlogsVote[$oBlog->getId()]);
            } else {
                $oBlog->setVote(null);
            }
        }
        return $aBlogs;
    }

    /**
     * Возвращает список блогов по ID
     *
     * @param array      $aBlogId    Список ID блогов
     * @param array|null $aOrder     Порядок сортировки
     *
     * @return array
     */
    public function GetBlogsByArrayId($aBlogId, $aOrder = null) {

        if (!$aBlogId) {
            return array();
        }
        if (Config::Get('sys.cache.solid')) {
            return $this->GetBlogsByArrayIdSolid($aBlogId, $aOrder);
        }
        if (!is_array($aBlogId)) {
            $aBlogId = array($aBlogId);
        }
        $aBlogId = array_unique($aBlogId);
        $aBlogs = array();
        $aBlogIdNotNeedQuery = array();
        /**
         * Делаем мульти-запрос к кешу
         */
        $aCacheKeys = F::Array_ChangeValues($aBlogId, 'blog_');
        if (false !== ($data = $this->Cache_Get($aCacheKeys))) {
            /**
             * проверяем что досталось из кеша
             */
            foreach ($aCacheKeys as $sValue => $sKey) {
                if (array_key_exists($sKey, $data)) {
                    if ($data[$sKey]) {
                        $aBlogs[$data[$sKey]->getId()] = $data[$sKey];
                    } else {
                        $aBlogIdNotNeedQuery[] = $sValue;
                    }
                }
            }
        }
        /**
         * Смотрим каких блогов не было в кеше и делаем запрос в БД
         */
        $aBlogIdNeedQuery = array_diff($aBlogId, array_keys($aBlogs));
        $aBlogIdNeedQuery = array_diff($aBlogIdNeedQuery, $aBlogIdNotNeedQuery);
        $aBlogIdNeedStore = $aBlogIdNeedQuery;
        if ($data = $this->oMapper->GetBlogsByArrayId($aBlogIdNeedQuery)) {
            foreach ($data as $oBlog) {
                /**
                 * Добавляем к результату и сохраняем в кеш
                 */
                $aBlogs[$oBlog->getId()] = $oBlog;
                $this->Cache_Set($oBlog, "blog_{$oBlog->getId()}", array(), 'P4D');
                $aBlogIdNeedStore = array_diff($aBlogIdNeedStore, array($oBlog->getId()));
            }
        }
        /**
         * Сохраняем в кеш запросы не вернувшие результата
         */
        foreach ($aBlogIdNeedStore as $sId) {
            $this->Cache_Set(null, "blog_{$sId}", array(), 'P4D');
        }
        /**
         * Сортируем результат согласно входящему массиву
         */
        $aBlogs = F::Array_SortByKeysArray($aBlogs, $aBlogId);
        return $aBlogs;
    }

    /**
     * Возвращает список блогов по ID, но используя единый кеш
     *
     * @param array      $aBlogId    Список ID блогов
     * @param array|null $aOrder     Сортировка блогов
     *
     * @return array
     */
    public function GetBlogsByArrayIdSolid($aBlogId, $aOrder = null) {

        if (!is_array($aBlogId)) {
            $aBlogId = array($aBlogId);
        }
        $aBlogId = array_unique($aBlogId);
        $aBlogs = array();
        $sCacheKey = 'blog_id_' . join(',', $aBlogId);
        if (false === ($data = $this->Cache_Get($sCacheKey))) {
            $data = $this->oMapper->GetBlogsByArrayId($aBlogId, $aOrder);
            foreach ($data as $oBlog) {
                $aBlogs[$oBlog->getId()] = $oBlog;
            }
            $this->Cache_Set($aBlogs, $sCacheKey, array('blog_update'), 'P1D');
            return $aBlogs;
        }
        return $data;
    }

    /**
     * Получить персональный блог юзера
     *
     * @param int $sUserId    ID пользователя
     *
     * @return ModuleBlog_EntityBlog
     */
    public function GetPersonalBlogByUserId($sUserId) {

        $id = $this->oMapper->GetPersonalBlogByUserId($sUserId);
        return $this->GetBlogById($id);
    }

    /**
     * Получить блог по айдишнику(номеру)
     *
     * @param int $sBlogId    ID блога
     *
     * @return ModuleBlog_EntityBlog|null
     */
    public function GetBlogById($sBlogId) {

        if (!intval($sBlogId)) {
            return null;
        }
        $aBlogs = $this->GetBlogsAdditionalData($sBlogId);
        if (isset($aBlogs[$sBlogId])) {
            return $aBlogs[$sBlogId];
        }
        return null;
    }

    /**
     * Получить блог по УРЛу
     *
     * @param   string $sBlogUrl    URL блога
     *
     * @return  ModuleBlog_EntityBlog|null
     */
    public function GetBlogByUrl($sBlogUrl) {

        if (false === ($id = $this->Cache_Get("blog_url_{$sBlogUrl}"))) {
            if ($id = $this->oMapper->GetBlogByUrl($sBlogUrl)) {
                $this->Cache_Set($id, "blog_url_{$sBlogUrl}", array("blog_update_{$id}"), 'P2D');
            } else {
                $this->Cache_Set(null, "blog_url_{$sBlogUrl}", array('blog_update', 'blog_new'), 60 * 60);
            }
        }
        return $this->GetBlogById($id);
    }

    /**
     * Получить блог по названию
     *
     * @param string $sTitle    Название блога
     *
     * @return ModuleBlog_EntityBlog|null
     */
    public function GetBlogByTitle($sTitle) {

        if (false === ($id = $this->Cache_Get("blog_title_{$sTitle}"))) {
            if ($id = $this->oMapper->GetBlogByTitle($sTitle)) {
                $this->Cache_Set($id, "blog_title_{$sTitle}", array("blog_update_{$id}", 'blog_new'), 'P2D');
            } else {
                $this->Cache_Set(null, "blog_title_{$sTitle}", array('blog_update', 'blog_new'), 60 * 60);
            }
        }
        return $this->GetBlogById($id);
    }

    /**
     * Создаёт персональный блог
     *
     * @param ModuleUser_EntityUser $oUser    Пользователь
     *
     * @return ModuleBlog_EntityBlog|bool
     */
    public function CreatePersonalBlog(ModuleUser_EntityUser $oUser) {

        $oBlogType = $this->GetBlogTypeByCode('personal');

        // Создаем персональный блог, только если это разрешено
        if ($oBlogType && $oBlogType->IsActive()) {
            $oBlog = Engine::GetEntity('Blog');
            $oBlog->setOwnerId($oUser->getId());
            $oBlog->setTitle($this->Lang_Get('blogs_personal_title') . ' ' . $oUser->getLogin());
            $oBlog->setType('personal');
            $oBlog->setDescription($this->Lang_Get('blogs_personal_description'));
            $oBlog->setDateAdd(F::Now());
            $oBlog->setLimitRatingTopic(-1000);
            $oBlog->setUrl(null);
            $oBlog->setAvatar(null);
            return $this->AddBlog($oBlog);
        }
    }

    /**
     * Добавляет блог
     *
     * @param ModuleBlog_EntityBlog $oBlog    Блог
     *
     * @return ModuleBlog_EntityBlog|bool
     */
    public function AddBlog(ModuleBlog_EntityBlog $oBlog) {

        if ($sId = $this->oMapper->AddBlog($oBlog)) {
            $oBlog->setId($sId);
            //чистим зависимые кеши
            $this->Cache_CleanByTags(array('blog_new'));
            return $oBlog;
        }
        return false;
    }

    /**
     * Обновляет блог
     *
     * @param ModuleBlog_EntityBlog $oBlog    Блог
     *
     * @return ModuleBlog_EntityBlog|bool
     */
    public function UpdateBlog(ModuleBlog_EntityBlog $oBlog) {

        $oBlog->setDateEdit(F::Now());
        $bResult = $this->oMapper->UpdateBlog($oBlog);
        if ($bResult) {
            //чистим зависимые кеши
            $aTags = array('blog_update', "blog_update_{$oBlog->getId()}", 'topic_update');
            if ($oBlog->getOldType() && $oBlog->getOldType() != $oBlog->getType()) {
                // Списк авторов блога
                $aUsersId = $this->GetAuthorsIdByBlog($oBlog->GetId());
                foreach($aUsersId as $nUserId) {
                    $aTags[] = 'topic_update_user_' . $nUserId;
                }
            }
            $this->Cache_CleanByTags($aTags);
            $this->Cache_Delete("blog_{$oBlog->getId()}");
            return true;
        }
        return false;
    }

    /**
     * Добавляет отношение юзера к блогу, по сути присоединяет к блогу
     *
     * @param ModuleBlog_EntityBlogUser $oBlogUser    Объект связи(отношения) блога с пользователем
     *
     * @return bool
     */
    public function AddRelationBlogUser(ModuleBlog_EntityBlogUser $oBlogUser) {

        if ($this->oMapper->AddRelationBlogUser($oBlogUser)) {
            $this->Cache_CleanByTags(
                array("blog_relation_change_{$oBlogUser->getUserId()}",
                      "blog_relation_change_blog_{$oBlogUser->getBlogId()}")
            );
            $this->Cache_Delete("blog_relation_user_{$oBlogUser->getBlogId()}_{$oBlogUser->getUserId()}");
            return true;
        }
        return false;
    }

    /**
     * Удалет отношение юзера к блогу, по сути отключает от блога
     *
     * @param ModuleBlog_EntityBlogUser $oBlogUser    Объект связи(отношения) блога с пользователем
     *
     * @return bool
     */
    public function DeleteRelationBlogUser(ModuleBlog_EntityBlogUser $oBlogUser) {

        if ($this->oMapper->DeleteRelationBlogUser($oBlogUser)) {
            $this->Cache_CleanByTags(
                array("blog_relation_change_{$oBlogUser->getUserId()}",
                      "blog_relation_change_blog_{$oBlogUser->getBlogId()}")
            );
            $this->Cache_Delete("blog_relation_user_{$oBlogUser->getBlogId()}_{$oBlogUser->getUserId()}");
            return true;
        }
        return false;
    }

    /**
     * Получает список блогов по хозяину
     *
     * @param int  $sUserId          ID пользователя
     * @param bool $bReturnIdOnly    Возвращать только ID блогов или полные объекты
     *
     * @return array
     */
    public function GetBlogsByOwnerId($sUserId, $bReturnIdOnly = false) {

        $data = $this->oMapper->GetBlogsByOwnerId($sUserId);

        // * Возвращаем только иденитификаторы
        if ($bReturnIdOnly) {
            return $data;
        }

        $data = $this->GetBlogsAdditionalData($data);
        return $data;
    }

    /**
     * Получает список всех НЕ персональных блогов
     *
     * @param bool $bReturnIdOnly    Возвращать только ID блогов или полные объекты
     *
     * @return array
     */
    public function GetBlogs($bReturnIdOnly = false) {

        $data = $this->oMapper->GetBlogs();
        // * Возвращаем только иденитификаторы
        if ($bReturnIdOnly) {
            return $data;
        }

        $data = $this->GetBlogsAdditionalData($data);
        return $data;
    }

    /**
     * Получает список пользователей блога.
     * Если роль не указана, то считаем что поиск производиться по положительным значениям (статусом выше GUEST).
     *
     * @param int      $nBlogId     ID блога
     * @param int|null $iRole       Роль пользователей в блоге
     * @param int      $iPage       Номер текущей страницы
     * @param int      $iPerPage    Количество элементов на одну страницу
     *
     * @return array
     */
    public function GetBlogUsersByBlogId($nBlogId, $iRole = null, $iPage = 1, $iPerPage = 100) {

        $aFilter = array(
            'blog_id' => $nBlogId,
        );
        if ($iRole !== null) {
            $aFilter['user_role'] = $iRole;
        }
        $s = serialize($aFilter);
        if (false === ($data = $this->Cache_Get("blog_relation_user_by_filter_{$s}_{$iPage}_{$iPerPage}"))) {
            $data = array('collection' => $this->oMapper->GetBlogUsers($aFilter, $iCount, $iPage, $iPerPage),
                          'count'      => $iCount);
            $this->Cache_Set(
                $data, "blog_relation_user_by_filter_{$s}_{$iPage}_{$iPerPage}",
                array("blog_relation_change_blog_{$nBlogId}"), 60 * 60 * 24 * 3
            );
        }

        // * Достаем дополнительные данные, для этого формируем список юзеров и делаем мульти-запрос
        if ($data['collection']) {
            $aUserId = array();
            foreach ($data['collection'] as $oBlogUser) {
                $aUserId[] = $oBlogUser->getUserId();
            }
            $aUsers = $this->User_GetUsersAdditionalData($aUserId);
            $aBlogs = $this->Blog_GetBlogsAdditionalData($nBlogId);

            $aResults = array();
            foreach ($data['collection'] as $oBlogUser) {
                if (isset($aUsers[$oBlogUser->getUserId()])) {
                    $oBlogUser->setUser($aUsers[$oBlogUser->getUserId()]);
                } else {
                    $oBlogUser->setUser(null);
                }
                if (isset($aBlogs[$oBlogUser->getBlogId()])) {
                    $oBlogUser->setBlog($aBlogs[$oBlogUser->getBlogId()]);
                } else {
                    $oBlogUser->setBlog(null);
                }
                $aResults[$oBlogUser->getUserId()] = $oBlogUser;
            }
            $data['collection'] = $aResults;
        }
        return $data;
    }

    /**
     * Получает отношения юзера к блогам(состоит в блоге или нет)
     *
     * @param int      $sUserId          ID пользователя
     * @param int|null $iRole            Роль пользователя в блоге
     * @param bool     $bReturnIdOnly    Возвращать только ID блогов или полные объекты
     *
     * @return array
     */
    public function GetBlogUsersByUserId($sUserId, $iRole = null, $bReturnIdOnly = false) {

        $aFilter = array(
            'user_id' => $sUserId
        );
        if ($iRole !== null) {
            $aFilter['user_role'] = $iRole;
        }
        $sCacheKey = 'blog_relation_user_by_filter_' . serialize($aFilter);
        if (false === ($data = $this->Cache_Get($sCacheKey))) {
            $data = $this->oMapper->GetBlogUsers($aFilter);
            $this->Cache_Set(
                $data, $sCacheKey, array('blog_update', "blog_relation_change_{$sUserId}"), 60 * 60 * 24 * 3
            );
        }
        /**
         * Достаем дополнительные данные, для этого формируем список блогов и делаем мульти-запрос
         */
        $aBlogId = array();
        if ($data) {
            foreach ($data as $oBlogUser) {
                $aBlogId[] = $oBlogUser->getBlogId();
            }
            /**
             * Если указано возвращать полные объекты
             */
            if (!$bReturnIdOnly) {
                $aUsers = $this->User_GetUsersAdditionalData($sUserId);
                $aBlogs = $this->Blog_GetBlogsAdditionalData($aBlogId);
                foreach ($data as $oBlogUser) {
                    if (isset($aUsers[$oBlogUser->getUserId()])) {
                        $oBlogUser->setUser($aUsers[$oBlogUser->getUserId()]);
                    } else {
                        $oBlogUser->setUser(null);
                    }
                    if (isset($aBlogs[$oBlogUser->getBlogId()])) {
                        $oBlogUser->setBlog($aBlogs[$oBlogUser->getBlogId()]);
                    } else {
                        $oBlogUser->setBlog(null);
                    }
                }
            }
        }
        return ($bReturnIdOnly) ? $aBlogId : $data;
    }

    /**
     * Состоит ли юзер в конкретном блоге
     *
     * @param int $sBlogId    ID блога
     * @param int $sUserId    ID пользователя
     *
     * @return ModuleBlog_EntityBlogUser|null
     */
    public function GetBlogUserByBlogIdAndUserId($sBlogId, $sUserId) {

        if ($aBlogUser = $this->GetBlogUsersByArrayBlog($sBlogId, $sUserId)) {
            if (isset($aBlogUser[$sBlogId])) {
                return $aBlogUser[$sBlogId];
            }
        }
        return null;
    }

    /**
     * Получить список отношений блог-юзер по списку айдишников
     *
     * @param array $aBlogId    Список ID блогов
     * @param int   $nUserId    ID пользователя
     *
     * @return array
     */
    public function GetBlogUsersByArrayBlog($aBlogId, $nUserId) {

        if (!$aBlogId) {
            return array();
        }
        if (Config::Get('sys.cache.solid')) {
            return $this->GetBlogUsersByArrayBlogSolid($aBlogId, $nUserId);
        }
        if (!is_array($aBlogId)) {
            $aBlogId = array($aBlogId);
        }
        $aBlogId = array_unique($aBlogId);
        $aBlogUsers = array();
        $aBlogIdNotNeedQuery = array();
        /**
         * Делаем мульти-запрос к кешу
         */
        $aCacheKeys = F::Array_ChangeValues($aBlogId, 'blog_relation_user_', '_' . $nUserId);
        if (false !== ($data = $this->Cache_Get($aCacheKeys))) {
            /**
             * проверяем что досталось из кеша
             */
            foreach ($aCacheKeys as $sValue => $sKey) {
                if (array_key_exists($sKey, $data)) {
                    if ($data[$sKey]) {
                        $aBlogUsers[$data[$sKey]->getBlogId()] = $data[$sKey];
                    } else {
                        $aBlogIdNotNeedQuery[] = $sValue;
                    }
                }
            }
        }
        /**
         * Смотрим каких блогов не было в кеше и делаем запрос в БД
         */
        $aBlogIdNeedQuery = array_diff($aBlogId, array_keys($aBlogUsers));
        $aBlogIdNeedQuery = array_diff($aBlogIdNeedQuery, $aBlogIdNotNeedQuery);
        $aBlogIdNeedStore = $aBlogIdNeedQuery;
        if ($data = $this->oMapper->GetBlogUsersByArrayBlog($aBlogIdNeedQuery, $nUserId)) {
            foreach ($data as $oBlogUser) {
                /**
                 * Добавляем к результату и сохраняем в кеш
                 */
                $aBlogUsers[$oBlogUser->getBlogId()] = $oBlogUser;
                $this->Cache_Set(
                    $oBlogUser, "blog_relation_user_{$oBlogUser->getBlogId()}_{$oBlogUser->getUserId()}", array(), 'P4D'
                );
                $aBlogIdNeedStore = array_diff($aBlogIdNeedStore, array($oBlogUser->getBlogId()));
            }
        }
        /**
         * Сохраняем в кеш запросы не вернувшие результата
         */
        foreach ($aBlogIdNeedStore as $sId) {
            $this->Cache_Set(null, "blog_relation_user_{$sId}_{$nUserId}", array(), 60 * 60 * 24 * 4);
        }
        /**
         * Сортируем результат согласно входящему массиву
         */
        $aBlogUsers = F::Array_SortByKeysArray($aBlogUsers, $aBlogId);
        return $aBlogUsers;
    }

    /**
     * Получить список отношений блог-юзер по списку айдишников используя общий кеш
     *
     * @param array $aBlogId    Список ID блогов
     * @param int   $sUserId    ID пользователя
     *
     * @return array
     */
    public function GetBlogUsersByArrayBlogSolid($aBlogId, $sUserId) {

        if (!is_array($aBlogId)) {
            $aBlogId = array($aBlogId);
        }
        $aBlogId = array_unique($aBlogId);
        $aBlogUsers = array();
        $s = join(',', $aBlogId);
        if (false === ($data = $this->Cache_Get("blog_relation_user_{$sUserId}_id_{$s}"))) {
            $data = $this->oMapper->GetBlogUsersByArrayBlog($aBlogId, $sUserId);
            foreach ($data as $oBlogUser) {
                $aBlogUsers[$oBlogUser->getBlogId()] = $oBlogUser;
            }
            $this->Cache_Set(
                $aBlogUsers, "blog_relation_user_{$sUserId}_id_{$s}",
                array('blog_update', "blog_relation_change_{$sUserId}"), 60 * 60 * 24 * 1
            );
            return $aBlogUsers;
        }
        return $data;
    }

    /**
     * Обновляет отношения пользователя с блогом
     *
     * @param ModuleBlog_EntityBlogUser $oBlogUser    Объект отновшения
     *
     * @return bool
     */
    public function UpdateRelationBlogUser(ModuleBlog_EntityBlogUser $oBlogUser) {

        $bResult = $this->oMapper->UpdateRelationBlogUser($oBlogUser);
        if ($bResult) {
            $this->Cache_CleanByTags(
                array("blog_relation_change_{$oBlogUser->getUserId()}",
                      "blog_relation_change_blog_{$oBlogUser->getBlogId()}")
            );
            $this->Cache_Delete("blog_relation_user_{$oBlogUser->getBlogId()}_{$oBlogUser->getUserId()}");
            return $bResult;
        }
    }

    /**
     * Возвращает список ID пользователей, являющихся авторами в блоге
     *
     * @param $xBlogId
     *
     * @return array
     */
    public function GetAuthorsIdByBlog($xBlogId) {

        $nBlogId = $this->_entityId($xBlogId);
        if ($nBlogId) {
            $sCacheKey = 'authors_id_by_blog_' . $nBlogId;
            if (false === ($data = $this->Cache_Get($sCacheKey))) {
                $data = $this->oMapper->GetAuthorsIdByBlogId($nBlogId);
                $this->Cache_Set($data, $sCacheKey, array('blog_update', 'blog_new', 'topic_new', 'topic_update'), 'P1D');
            }
            return $data;
        }
        return array();
    }

    /**
     * Возвращает список блогов по фильтру
     *
     * @param array $aFilter         Фильтр выборки блогов
     * @param array $aOrder          Сортировка блогов
     * @param int   $nCurrPage       Номер текущей страницы
     * @param int   $nPerPage        Количество элементов на одну страницу
     * @param array $aAllowData      Список типов данных, которые нужно подтянуть к списку блогов
     *
     * @return array('collection'=>array,'count'=>int)
     */
    public function GetBlogsByFilter($aFilter, $aOrder, $nCurrPage, $nPerPage, $aAllowData = null) {

        if (is_null($aAllowData)) {
            $aAllowData = array('owner' => array(), 'relation_user');
        }
        $sCacheKey = 'blog_filter_' . serialize($aFilter) . serialize($aOrder) . "_{$nCurrPage}_{$nPerPage}";
        if (false === ($data = $this->Cache_Get($sCacheKey))) {
            $data = array(
                'collection' => $this->oMapper->GetBlogsByFilter($aFilter, $aOrder, $iCount, $nCurrPage, $nPerPage),
                'count'      => $iCount
            );
            $this->Cache_Set($data, $sCacheKey, array('blog_update', 'blog_new'), 'P2D');
        }
        $data['collection'] = $this->GetBlogsAdditionalData($data['collection'], $aAllowData);
        return $data;
    }

    /**
     * Получает список блогов по рейтингу
     *
     * @param int $nCurrPage       Номер текущей страницы
     * @param int $nPerPage        Количество элементов на одну страницу
     *
     * @return array('collection'=>array,'count'=>int)
     */
    public function GetBlogsRating($nCurrPage, $nPerPage) {

        return $this->GetBlogsByFilter(
            array('include_type' => $this->GetAllowBlogTypes($this->oUserCurrent, 'list', true)),
            array('blog_rating' => 'desc'),
            $nCurrPage,
            $nPerPage
        );
    }

    /**
     * Список подключенных блогов по рейтингу
     *
     * @param int $nUserId    ID пользователя
     * @param int $nLimit     Ограничение на количество в ответе
     *
     * @return array
     */
    public function GetBlogsRatingJoin($nUserId, $nLimit) {

        $sCacheKey = "blog_rating_join_{$nUserId}_{$nLimit}";
        if (false === ($data = $this->Cache_Get($sCacheKey))) {
            $data = $this->oMapper->GetBlogsRatingJoin($nUserId, $nLimit);
            $this->Cache_Set($data, $sCacheKey, array('blog_update', "blog_relation_change_{$nUserId}"), 'P1D');
        }
        return $data;
    }

    /**
     * Список своих блогов по рейтингу
     *
     * @param int $nUserId    ID пользователя
     * @param int $nLimit     Ограничение на количество в ответе
     *
     * @return array
     */
    public function GetBlogsRatingSelf($nUserId, $nLimit) {

        $aResult = $this->GetBlogsByFilter(
            array('exclude_type' => 'personal', 'user_owner_id' => $nUserId),
            array('blog_rating' => 'desc'), 1, $nLimit
        );
        return $aResult['collection'];
    }

    /**
     * Получает список блогов в которые может постить юзер
     *
     * @param ModuleUser_EntityUser $oUser    Объект пользователя
     *
     * @return array
     */
    public function GetBlogsAllowByUser($oUser) {

        if ($oUser->isAdministrator()) {
            return $this->GetBlogs();
        } else {
            $aAllowBlogsUser = $this->GetBlogsByOwnerId($oUser->getId());
            $aBlogUsers = $this->GetBlogUsersByUserId($oUser->getId());
            foreach ($aBlogUsers as $oBlogUser) {
                $oBlog = $oBlogUser->getBlog();
                if ($oBlogUser->getIsAdministrator() || $oBlogUser->getIsModerator()
                    || $this->ACL_CanAddTopic($oUser, $oBlog)
                ) {
                    $aAllowBlogsUser[$oBlog->getId()] = $oBlog;
                }
            }
            return $aAllowBlogsUser;
        }
    }

    /**
     * Получаем массив блогов, которые являются открытыми для пользователя
     *
     * @param  ModuleUser_EntityUser $oUser    Объект пользователя
     *
     * @return array
     */
    public function GetAccessibleBlogsByUser($oUser) {

        if ($oUser->isAdministrator()) {
            return $this->GetBlogs(true);
        }
        if (false === ($aOpenBlogsUser = $this->Cache_Get("blog_accessible_user_{$oUser->getId()}"))) {
            /**
             * Заносим блоги, созданные пользователем
             */
            $aOpenBlogsUser = $this->GetBlogsByOwnerId($oUser->getId(), true);
            /**
             * Добавляем блоги, в которых состоит пользователь
             * (читателем, модератором, или администратором)
             */
            $aOpenBlogsUser = array_merge($aOpenBlogsUser, $this->GetBlogUsersByUserId($oUser->getId(), null, true));
            $this->Cache_Set(
                $aOpenBlogsUser, "blog_accessible_user_{$oUser->getId()}",
                array('blog_new', 'blog_update', "blog_relation_change_{$oUser->getId()}"), 60 * 60 * 24
            );
        }
        return $aOpenBlogsUser;
    }

    /**
     * Получаем массив идентификаторов блогов, которые являются закрытыми для пользователя
     *
     * @param  ModuleUser_EntityUser|null $oUser    Пользователь
     *
     * @return array
     */
    public function GetInaccessibleBlogsByUser($oUser = null) {

        if ($oUser && $oUser->isAdministrator()) {
            return array();
        }
        $nUserId = $oUser ? $oUser->getId() : 0;
        $sCacheKey = 'blog_inaccessible_user_' . $nUserId;
        if (false === ($aCloseBlogs = $this->Cache_Get($sCacheKey))) {
            $aCloseBlogs = $this->oMapper->GetCloseBlogs();

            if ($oUser) {
                /**
                 * Получаем массив идентификаторов блогов,
                 * которые являются откытыми для данного пользователя
                 */
                $aOpenBlogs = $this->GetBlogUsersByUserId($nUserId, null, true);
                /**
                 * Получаем закрытые блоги, где пользователь является автором
                 */
                $aOwnerBlogs = $this->GetBlogsByFilter(
                    array(
                         'type' => 'close',
                         'user_owner_id' => $nUserId,
                    ),
                    array(), 1, 100, array()
                );
                $aOwnerBlogs = array_keys($aOwnerBlogs['collection']);
                $aCloseBlogs = array_diff($aCloseBlogs, $aOpenBlogs, $aOwnerBlogs);
            }
            /**
             * Сохраняем в кеш
             */
            if ($oUser) {
                $this->Cache_Set(
                    $aCloseBlogs, $sCacheKey,
                    array('blog_new', 'blog_update', "blog_relation_change_{$nUserId}"), 60 * 60 * 24
                );
            } else {
                $this->Cache_Set(
                    $aCloseBlogs, $sCacheKey, array('blog_new', 'blog_update'),
                    60 * 60 * 24 * 3
                );
            }
        }
        return $aCloseBlogs;
    }

    /**
     * Удаляет блог
     *
     * @param   int|array $aBlogsId   ID блога|массив ID блогов
     *
     * @return  bool
     */
    public function DeleteBlog($aBlogsId) {

        // Получаем массив ID, если передан объект или массив объектов
        $aBlogsId = $this->_entitiesId($aBlogsId);

        // * Получаем идентификаторы топиков блога. Удаляем топики блога.
        // * При удалении топиков удаляются комментарии к ним и голоса.
        $aTopicsId = $this->Topic_GetTopicsByBlogId($aBlogsId);

        // * Если блог не удален, возвращаем false
        if (!$this->oMapper->DeleteBlog($aBlogsId)) {
            return false;
        }

        if ($aTopicsId) {
            // * Удаляем топики
            $this->Topic_DeleteTopic($aTopicsId);
            foreach ($aTopicsId as $nTopicId) {
                $this->Cache_Delete("topic_{$nTopicId}");
            }
        }

        // * Удаляем связи пользователей блога.
        $this->oMapper->DeleteBlogUsersByBlogId($aBlogsId);

        // * Удаляем голосование за блог
        $this->Vote_DeleteVoteByTarget($aBlogsId, 'blog');

        // * Чистим кеш
        $this->Cache_CleanByTags(array('blog_update', 'topic_update', 'comment_online_update_topic', 'comment_update'));
        foreach ($aBlogsId as $nBlogId) {
            $this->Cache_CleanByTags(array("blog_relation_change_blog_{$nBlogId}"));
            $this->Cache_Delete("blog_{$nBlogId}");
        }
        return true;
    }

    /**
     * Удаление блогов по ID владельцев
     *
     * @param $aUsersId
     *
     * @return bool
     */
    public function DeleteBlogsByUsers($aUsersId) {

        $aBlogsId = $this->oMapper->GetBlogsIdByOwnersId($aUsersId);
        return $this->DeleteBlog($aBlogsId);
    }

    /**
     * Загружает аватар в блог
     *
     * @param array                 $aFile    Массив $_FILES при загрузке аватара
     * @param ModuleBlog_EntityBlog $oBlog    Блог
     *
     * @return bool
     */
    public function UploadBlogAvatar($aFile, $oBlog) {

        if (!is_array($aFile) || !isset($aFile['tmp_name'])) {
            return false;
        }

        $sFileTmp = Config::Get('sys.cache.dir') . F::RandomStr();
        if (!move_uploaded_file($aFile['tmp_name'], $sFileTmp)) {
            return false;
        }

        $sPath = $this->Image_GetIdDir($oBlog->getOwnerId());
        $aParams = $this->Image_BuildParams('avatar');

        $oImage = $this->Image_CreateImageObject($sFileTmp);
        /**
         * Если объект изображения не создан, возвращаем ошибку
         */
        if ($sError = $oImage->get_last_error()) {
            // Вывод сообщения об ошибки, произошедшей при создании объекта изображения
            // $this->Message_AddError($sError,$this->Lang_Get('error'));
            @unlink($sFileTmp);
            return false;
        }
        /**
         * Срезаем квадрат
         */
        $oImage = $this->Image_CropSquare($oImage);

        $aSize = Config::Get('module.blog.avatar_size');
        rsort($aSize, SORT_NUMERIC);
        $sSizeBig = array_shift($aSize);
        if ($oImage
            && ($sFileAvatar = $this->Image_Resize(
                $sFileTmp, $sPath, "avatar_blog_{$oBlog->getUrl()}_{$sSizeBig}x{$sSizeBig}",
                Config::Get('view.img_max_width'), Config::Get('view.img_max_height'), $sSizeBig, $sSizeBig, false,
                $aParams, $oImage
            ))
        ) {
            foreach ($aSize as $iSize) {
                if ($iSize == 0) {
                    $this->Image_Resize(
                        $sFileTmp, $sPath, "avatar_blog_{$oBlog->getUrl()}", Config::Get('view.img_max_width'),
                        Config::Get('view.img_max_height'), null, null, false, $aParams, $oImage
                    );
                } else {
                    $this->Image_Resize(
                        $sFileTmp, $sPath, "avatar_blog_{$oBlog->getUrl()}_{$iSize}x{$iSize}",
                        Config::Get('view.img_max_width'), Config::Get('view.img_max_height'), $iSize, $iSize, false,
                        $aParams, $oImage
                    );
                }
            }
            @unlink($sFileTmp);
            /**
             * Если все нормально, возвращаем расширение загруженного аватара
             */
            return $this->Image_GetWebPath($sFileAvatar);
        }
        @unlink($sFileTmp);
        /**
         * В случае ошибки, возвращаем false
         */
        return false;
    }

    /**
     * Удаляет аватар блога с сервера
     *
     * @param ModuleBlog_EntityBlog $oBlog    Блог
     */
    public function DeleteBlogAvatar($oBlog) {
        /**
         * Если аватар есть, удаляем его и его рейсайзы
         */
        if ($oBlog->getAvatar()) {
            $aSize = array_merge(Config::Get('module.blog.avatar_size'), array(48));
            foreach ($aSize as $iSize) {
                $this->Image_RemoveFile($this->Image_GetServerPath($oBlog->getAvatarPath($iSize)));
            }
        }
    }

    /**
     * Пересчет количества топиков в блогах
     *
     * @return bool
     */
    public function RecalculateCountTopic() {

        $bResult = $this->oMapper->RecalculateCountTopic();
        if ($bResult) {
            //чистим зависимые кеши
            $this->Cache_CleanByTags(array('blog_update'));
            return $bResult;
        }
    }

    /**
     * Пересчет количества топиков в конкретном блоге
     *
     * @param int $iBlogId    ID блога
     *
     * @return bool
     */
    public function RecalculateCountTopicByBlogId($iBlogId) {

        $bResult = $this->oMapper->RecalculateCountTopic($iBlogId);
        if ($bResult) {
            //чистим зависимые кеши
            $this->Cache_CleanByTags(array('blog_update', "blog_update_{$iBlogId}"));
            $this->Cache_Delete("blog_{$iBlogId}");
            return $bResult;
        }
    }

    /**
     * Алиас для корректной работы ORM
     *
     * @param array $aBlogId    Список ID блогов
     *
     * @return array
     */
    public function GetBlogItemsByArrayId($aBlogId) {

        return $this->GetBlogsByArrayId($aBlogId);
    }

    /**
     * Возвращает список доступных типов для определенного действия
     *
     * @param      $oUser
     * @param      $sAction
     * @param bool $bTypeCodesOnly
     *
     * @return array
     */
    public function GetAllowBlogTypes($oUser, $sAction, $bTypeCodesOnly = false) {

        $aFilter = array(
            'exclude_type' => 'personal',
            'is_active' => true,
        );

        if ($sAction && !in_array($sAction, array('add', 'list'))) {
            return array();
        }

        if (!$oUser) {
            // Если пользователь не задан
            if ($sAction == 'add') {
                $aFilter['allow_add'] = true;
            } elseif ($sAction == 'list') {
                $aFilter['allow_list'] = true;
            }
        } elseif ($oUser && !$oUser->IsAdministrator()) {
            // Если пользователь задан и он не админ, то надо учитывать рейтинг
            if ($sAction == 'add') {
                $aFilter['allow_add'] = true;
                $aFilter['min_rate_add'] = $oUser->GetUserRating();
            } elseif ($sAction == 'list') {
                $aFilter['allow_list'] = true;
                $aFilter['min_rate_list'] = $oUser->GetUserRating();
            }
        }
        $aBlogTypes = $this->GetBlogTypes($aFilter, $bTypeCodesOnly);

        return $aBlogTypes;
    }

    /**
     * Получить типы блогов
     *
     * @param   array   $aFilter
     * @param   bool    $bTypeCodesOnly
     *
     * @return  array
     */
    public function GetBlogTypes($aFilter = array(), $bTypeCodesOnly = false) {

        $aResult = array();
        $sCacheKey = 'blog_types';
        if (false === ($data = $this->Cache_Get($sCacheKey, 'tmp'))) {
            $data = $this->oMapper->GetBlogTypes();
            $this->Cache_Set($data, $sCacheKey, array('blog_update', 'blog_new'), 'P30D', 'tmp');
        }
        $aBlogTypes = array();
        if ($data) {
            foreach ($data as $nKey => $oBlogType) {
                $bOk = true;
                if (isset($aFilter['include_type'])) {
                    $bOk = $bOk && ($aFilter['include_type'] == $oBlogType->GetTypeCode());
                    if (!$bOk) continue;
                }
                if (isset($aFilter['exclude_type'])) {
                    $bOk = $bOk && ($aFilter['exclude_type'] != $oBlogType->GetTypeCode());
                    if (!$bOk) continue;
                }
                if (isset($aFilter['is_active'])) {
                    $bOk = $bOk && $oBlogType->IsActive();
                    if (!$bOk) continue;
                }
                if (isset($aFilter['not_active'])) {
                    $bOk = $bOk && !$oBlogType->IsActive();
                    if (!$bOk) continue;
                }
                if (isset($aFilter['allow_add'])) {
                    $bOk = $bOk && $oBlogType->IsAllowAdd();
                    if (!$bOk) continue;
                }
                if (isset($aFilter['allow_list'])) {
                    $bOk = $bOk && $oBlogType->IsShowTitle();
                    if (!$bOk) continue;
                }
                if (isset($aFilter['min_rate_add'])) {
                    $bOk = $bOk && ($oBlogType->GetMinRateAdd() <= $aFilter['min_rate_add']);
                    if (!$bOk) continue;
                }
                if (isset($aFilter['min_rate_list'])) {
                    $bOk = $bOk && ($oBlogType->GetMinRateList() <= $aFilter['min_rate_list']);
                    if (!$bOk) continue;
                }
                if ($bOk) {
                    $aBlogTypes[$oBlogType->GetTypeCode()] = $oBlogType;
                }
                $data[$nKey] = null;
            }
        }
        if ($aBlogTypes) {
            if ($bTypeCodesOnly) {
                $aResult = array_keys($aBlogTypes);
            } else {
                $aResult = $aBlogTypes;
            }
        }
        return $aResult;
    }

    /**
     * Получить объект типа блога по его ID
     *
     * @param $nId
     *
     * @return null|ModuleBlog_EntityBlogType
     */
    public function GetBlogTypeById($nId) {

        $sCacheKey = 'blog_type_' . $nId;
        if (false === ($data = $this->Cache_Get($sCacheKey))) {
            $data = $this->oMapper->GetBlogTypeById($nId);
            $this->Cache_Set($data, $sCacheKey, array('blog_update', 'blog_new'), 'PT30M');
        }
        return $data;
    }

    /**
     * Получить объект типа блога по его коду
     *
     * @param $sTypeCode
     *
     * @return null|ModuleBlog_EntityBlogType
     */
    public function GetBlogTypeByCode($sTypeCode) {

        $aBlogTypes = $this->GetBlogTypes();
        if (isset($aBlogTypes[$sTypeCode])) {
            return $aBlogTypes[$sTypeCode];
        }
        return null;
    }

    /**
     * Добавить тип блога
     *
     * @param $oBlogType
     *
     * @return bool
     */
    public function AddBlogType($oBlogType) {

        $nId = $this->oMapper->AddBlogType($oBlogType);
        if ($nId) {
            $oBlogType->SetId($nId);
            //чистим зависимые кеши
            $this->Cache_CleanByTags(array('blog_update'));
            $this->Cache_Delete("blog_type_{$oBlogType->getId()}");
            return true;
        }
        return false;
    }

    /**
     * Обновить тип блога
     *
     * @param $oBlogType
     *
     * @return bool
     */
    public function UpdateBlogType($oBlogType) {

        $bResult = $this->oMapper->UpdateBlogType($oBlogType);
        if ($bResult) {
            //чистим зависимые кеши
            $this->Cache_CleanByTags(array('blog_update'));
            $this->Cache_Delete("blog_type_{$oBlogType->getId()}");
            return true;
        }
        return false;
    }

    /**
     * Удалить тип блога
     *
     * @param $oBlogType
     *
     * @return bool
     */
    public function DeleteBlogType($oBlogType) {

        $nCount = $this->oMapper->GetBlogCountsByTypes($oBlogType->GetTypeCode());
        // Если есть блоги такого типа, то НЕ удаляем тип
        if (!$nCount) {
            $bResult = $this->oMapper->DeleteBlogType($oBlogType);
            if ($bResult) {
                //чистим зависимые кеши
                $this->Cache_CleanByTags(array('blog_update'));
                $this->Cache_Delete("blog_type_{$oBlogType->getId()}");
                return true;
            }
        }
        return false;
    }

    /**
     * Активен ли этот тип блога
     *
     * @param $sBlogType
     *
     * @return bool
     */
    public function BlogTypeEnabled($sBlogType) {

        $oBlogType = $this->GetBlogTypeByCode($sBlogType);
        return $oBlogType && $oBlogType->IsActive();
    }

    /**
     * Статистка блогов
     *
     * @param array $aExcludeTypes
     *
     * @return array
     */
    public function GetBlogsData($aExcludeTypes = array('personal')) {

        return $this->oMapper->GetBlogsData($aExcludeTypes);
    }

    /*********************************************************/

    public function GetBlogsId($aFilter) {


    }
}

// EOF