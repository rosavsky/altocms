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
 * Абстракция плагина, от которой наследуются все плагины
 * Файл плагина должен находиться в каталоге /plugins/plgname/ и иметь название PluginPlgname.class.php
 *
 * @package engine
 * @since   1.0
 */
abstract class Plugin extends LsObject {
    /**
     * Путь к шаблонам с учетом наличия соответствующего skin`a
     *
     * @var array
     */
    static protected $aTemplateDir = array();
    /**
     * Web-адреса шаблонов с учетом наличия соответствующего skin`a
     *
     * @var array
     */
    static protected $aTemplateUrl = array();
    /**
     * Массив делегатов плагина
     *
     * @var array
     */
    protected $aDelegates = array();
    /**
     * Массив наследуемых классов плагина
     *
     * @var array
     */
    protected $aInherits = array();

    protected $oPluginEntity;

    public function __construct() {

        $this->oPluginEntity = $this->GetPluginEntity();
    }

    /**
     * Метод инициализации плагина
     *
     */
    public function Init() {
    }

    /**
     * Передает информацию о делегатах в модуль ModulePlugin
     * Вызывается Engine перед инициализацией плагина
     *
     * @see Engine::LoadPlugins
     */
    final function Delegate() {

        $aDelegates = $this->GetDelegates();
        foreach ($aDelegates as $sObjectName => $aParams) {
            foreach ($aParams as $sFrom => $sTo) {
                $this->Plugin_Delegate($sObjectName, $sFrom, $sTo, get_class($this));
            }
        }

        $aInherits = $this->GetInherits();
        foreach ($aInherits as $aParams) {
            foreach ($aParams as $sFrom => $sTo) {
                $this->Plugin_Inherit($sFrom, $sTo, get_class($this));
            }
        }
    }

    /**
     * Возвращает массив наследников
     *
     * @return array
     */
    final function GetInherits() {

        $aReturn = array();
        if (is_array($this->aInherits) && count($this->aInherits)) {
            foreach ($this->aInherits as $sObjectName => $aParams) {
                if (is_array($aParams) && count($aParams)) {
                    foreach ($aParams as $sFrom => $sTo) {
                        if (is_int($sFrom)) {
                            $sFrom = $sTo;
                            $sTo = null;
                        }
                        list($sFrom, $sTo) = $this->MakeDelegateParams($sObjectName, $sFrom, $sTo);
                        $aReturn[$sObjectName][$sFrom] = $sTo;
                    }
                }
            }
        }
        return $aReturn;
    }

    /**
     * Возвращает массив делегатов
     *
     * @return array
     */
    final function GetDelegates() {

        $aReturn = array();
        if (is_array($this->aDelegates) && count($this->aDelegates)) {
            foreach ($this->aDelegates as $sObjectName => $aParams) {
                if (is_array($aParams) && count($aParams)) {
                    foreach ($aParams as $sFrom => $sTo) {
                        if (is_int($sFrom)) {
                            $sFrom = $sTo;
                            $sTo = null;
                        }
                        list($sFrom, $sTo) = $this->MakeDelegateParams($sObjectName, $sFrom, $sTo);
                        $aReturn[$sObjectName][$sFrom] = $sTo;
                    }
                }
            }
        }
        return $aReturn;
    }

    /**
     * Преобразовывает краткую форму имен делегатов в полную
     *
     * @param $sObjectName    Название типа объекта делегата
     *
     * @see ModulePlugin::aDelegates
     *
     * @param $sFrom          Что делегируем
     * @param $sTo            Что делегирует
     *
     * @return array
     */
    public function MakeDelegateParams($sObjectName, $sFrom, $sTo) {
        /**
         * Если не указан делегат то, считаем, что делегатом является
         * одноименный объект текущего плагина
         */
        if ($sObjectName == 'template') {
            if (!$sTo) {
                $sTo = self::GetTemplateDir(get_class($this)) . $sFrom;
            } else {
                $sTo = preg_replace("/^_/", self::GetTemplateDir(get_class($this)), $sTo);
            }
        } else {
            if (!$sTo) {
                $sTo = get_class($this) . '_' . $sFrom;
            } else {
                $sTo = preg_replace("/^_/", get_class($this) . '_', $sTo);
            }
        }
        return array($sFrom, $sTo);
    }

    /**
     * Метод активации плагина
     *
     * @return bool
     */
    public function Activate() {

        return true;
    }

    /**
     * Метод деактивации плагина
     *
     * @return bool
     */
    public function Deactivate() {

        return true;
    }

    /**
     * Метод удаления плагина
     *
     * @return bool
     */
    public function Remove() {

        return true;
    }

    /**
     * Транслирует на базу данных запросы из указанного файла
     * @see ModuleDatabase::ExportSQL
     *
     * @param  string $sFilePath    Полный путь до файла с SQL
     *
     * @return array
     */
    protected function ExportSQL($sFilePath) {

        return $this->Database_ExportSQL($sFilePath);
    }

    /**
     * Выполняет SQL
     *
     * @see ModuleDatabase::ExportSQLQuery
     *
     * @param string $sSql    Строка SQL запроса
     *
     * @return array
     */
    protected function ExportSQLQuery($sSql) {

        return $this->Database_ExportSQLQuery($sSql);
    }

    /**
     * Проверяет наличие таблицы в БД
     * @see ModuleDatabase::isTableExists
     *
     * @param string $sTableName    - Название таблицы, необходимо перед именем таблицы добавлять "prefix_",
     *                                это позволит учитывать произвольный префикс таблиц у пользователя
     * <pre>
     *                              prefix_topic
     * </pre>
     *
     * @return bool
     */
    protected function isTableExists($sTableName) {

        return $this->Database_isTableExists($sTableName);
    }

    /**
     * Проверяет наличие поля в таблице
     * @see ModuleDatabase::isFieldExists
     *
     * @param string $sTableName    - Название таблицы, необходимо перед именем таблицы добавлять "prefix_",
     *                                это позволит учитывать произвольный префикс таблиц у пользователя
     * @param string $sFieldName    - Название поля в таблице
     *
     * @return bool
     */
    protected function isFieldExists($sTableName, $sFieldName) {

        return $this->Database_isFieldExists($sTableName, $sFieldName);
    }

    /**
     * Добавляет новый тип в поле enum(перечисление)
     *
     * @see ModuleDatabase::addEnumType
     *
     * @param string $sTableName       - Название таблицы, необходимо перед именем таблицы добавлять "prefix_",
     *                                   это позволит учитывать произвольный префикс таблиц у пользователя
     * @param string $sFieldName       - Название поля в таблице
     * @param string $sType            - Название типа
     */
    protected function addEnumType($sTableName, $sFieldName, $sType) {

        $this->Database_addEnumType($sTableName, $sFieldName, $sType);
    }

    /**
     * Returns name of plugin
     *
     * @return string
     */
    public function GetName($bSkipPrefix = true) {

        $sName = get_class($this);
        return $bSkipPrefix ? substr($sName, 6) : $sName;
    }

    public function GetPluginEntity() {

        if (!$this->oPluginEntity) {
            $sPluginId = F::StrUnderscore($this->GetName());
            $this->oPluginEntity = Engine::GetEntity('Plugin', $sPluginId);
        }
        return $this->oPluginEntity;
    }

    /**
     * Возвращает версию плагина
     *
     * @return string|null
     */
    public function GetVersion() {

        if ($oPluginEntity = $this->GetPluginEntity()) {
            return $oPluginEntity->GetVersion();
        }
    }

    public function EngineCompatible() {

        if ($oPluginEntity = $this->GetPluginEntity()) {
            return $oPluginEntity->EngineCompatible();
        }
    }

    /**
     * Возвращает полный серверный путь до плагина
     *
     * @param string $sName
     *
     * @return string
     */
    static public function GetDir($sName) {

        $sName = preg_match('/^Plugin([\w]+)(_[\w]+)?$/Ui', $sName, $aMatches)
            ? strtolower($aMatches[1])
            : strtolower($sName);

        $aDirs = Config::Get('path.root.seek');
        foreach($aDirs as $sDir) {
            $sPluginDir = $sDir . '/plugins/' . $sName . '/';
            if (is_file($sPluginDir . 'plugin.xml')) {
                return F::File_NormPath($sPluginDir);
            }
        }
    }

    /**
     * Возвращает полный web-адрес до плагина
     *
     * @param string $sName
     *
     * @return string
     */
    static public function GetUrl($sName) {

        $sName = preg_match('/^Plugin([\w]+)(_[\w]+)?$/Ui', $sName, $aMatches)
            ? strtolower($aMatches[1])
            : strtolower($sName);

        return F::File_Dir2Url(self::GetDir($sName));
    }

    /**
     * Возвращает правильный серверный путь к директории шаблонов с учетом текущего шаблона
     * Если пользователь использует шаблон которого нет в плагине, то возвращает путь до шабона плагина 'default'
     *
     * @param string $sName    Название плагина или его класс
     *
     * @return string|null
     */
    static public function GetTemplateDir($sName) {

        $sName = preg_match('/^Plugin([\w]+)(_[\w]+)?$/Ui', $sName, $aMatches)
            ? strtolower($aMatches[1])
            : strtolower($sName);
        if (!isset(self::$aTemplateDir[$sName])) {
            $sPluginDir = self::GetDir($sName);
            $aPaths = glob($sPluginDir . '/templates/skin/*', GLOB_ONLYDIR);
            $sTemplateName = ($aPaths && in_array(Config::Get('view.skin'), array_map('basename', $aPaths)))
                ? Config::Get('view.skin')
                : 'default';

            $sDir = $sPluginDir . '/templates/skin/' . $sTemplateName . '/';
            self::$aTemplateDir[$sName] = is_dir($sDir) ? F::File_NormPath($sDir) : null;
        }
        return self::$aTemplateDir[$sName];
    }

    /**
     * LS-compatible
     */
    static public function GetTemplatePath($sName) {

        return self::GetTemplateDir($sName);
    }

    /**
     * Возвращает правильный web-адрес директории шаблонов
     * Если пользователь использует шаблон которого нет в плагине, то возвращает путь до шабона плагина 'default'
     *
     * @param   string $sName    Название плагина или его класс
     *
     * @return  string
     */
    static public function GetTemplateUrl($sName) {

        $sName = preg_match('/^Plugin([\w]+)(_[\w]+)?$/Ui', $sName, $aMatches)
            ? strtolower($aMatches[1])
            : strtolower($sName);
        if (!isset(self::$aTemplateUrl[$sName])) {
            if ($sTemplateDir = self::GetTemplateDir($sName)) {
                self::$aTemplateUrl[$sName] = F::File_Dir2Url($sTemplateDir);
            } else {
                self::$aTemplateUrl[$sName] = null;
            }
        }
        return self::$aTemplateUrl[$sName];
    }

    /**
     * Устанавливает значение серверного пути до шаблонов плагина
     *
     * @param  string $sName           Имя плагина
     * @param  string $sTemplateDir    Серверный путь до шаблона
     *
     * @return bool
     */
    static public function SetTemplateDir($sName, $sTemplateDir) {

        if (!is_dir($sTemplateDir)) {
            return false;
        }
        self::$aTemplateDir[$sName] = $sTemplateDir;
        return true;
    }

    /**
     * Устанавливает значение web-пути до шаблонов плагина
     *
     * @param  string $sName           Имя плагина
     * @param  string $sTemplateUrl    Серверный путь до шаблона
     */
    static public function SetTemplateUrl($sName, $sTemplateUrl) {

        self::$aTemplateUrl[$sName] = $sTemplateUrl;
    }

    /*************************************************************
     * LS-compatible
     */
    static public function GetTemplateWebPath($sName) {

        return self::GetTemplateUrl($sName);
    }

    static public function GetWebPath($sName) {

        return self::GetUrl($sName);
    }

    static public function GetPath($sName) {

        return self::GetDir($sName);
    }

    static public function SetTemplatePath($sName, $sTemplatePath) {

        return self::SetTemplateDir($sName, $sTemplatePath);
    }

    static public function SetTemplateWebPath($sName, $sTemplatePath) {

        return self::SetTemplateUrl($sName, $sTemplatePath);
    }

}

// EOF