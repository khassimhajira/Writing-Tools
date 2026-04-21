<?php

/**
 * Base class for custom theme
 * @package Am_Plugin
 */
class Am_Theme extends Am_Plugin_Base
{
    const THEME_CACHED_FILE_PREFIX = 'thc--'; // this prefix is required, so amember can safely remove all files in data/public with such prefix
    const THEME_CSS = "css/theme.css"; // main theme file
    const PUBLIC_THEME_CSS = "public/css/theme.css"; // main theme file path relating to theme folder
    const CHILD_NAME_PREFIX = 'child-'; // name your child as "child-PARENT_THEME_ID"
    const PARAM_NEED_RESET = 'need_reset'; // if theme should add reset.css or similar into start of refs

    // placeholder choosen to be compatible with scss syntax and compile without errors
    // will be replaced to actual config css vars
    const CSS_VARS_PLACEHOLDER = '--css_variables:placeholder;';

    protected $_idPrefix = 'Am_Theme_';
    protected $formThemeClassUser = null; // use default or set to className
    protected $needReset = true;
    /**
     * Array of paths (relative to application/default/themes/XXX/public/)
     * that must be routed via PHP to substitute vars
     * for example css/theme.css
     * all these files can be accessed directly so please do not put anything
     * sensitive inside
     * @var array
     */
    protected $publicWithVars = [];

    public function __construct(Am_Di $di, $id, array $config)
    {
        parent::__construct($di, $config);
        $this->id = $id;
        $rm = new ReflectionMethod(get_class($this), 'initSetupForm');
        if ($rm->getDeclaringClass()->getName() != __CLASS__)
        {
            $this->getDi()->hook->add(Am_Event::SETUP_FORMS, [$this, 'eventSetupForm']);
        }
        $this->config = $this->config + $this->getDefaults();
    }

    final public function hasSetupForm()
    {
        $rm = new ReflectionMethod(get_class($this), 'initSetupForm');
        return $rm->getDeclaringClass()->getName() != __CLASS__;
    }

    public function init()
    {
        parent::init();
        if ($this->formThemeClassUser)
        {
            $this->getDi()->register('formThemeUser', $this->formThemeClassUser)
                ->addArgument(new sfServiceReference('service_container'));
        }
    }

    function eventSetupForm(Am_Event_SetupForms $event)
    {
        $form = new Am_Form_Setup_Theme($this->getId());
        $form->setTitle(ucwords(str_replace('-', ' ', $this->getId())) . ' Theme');
        $this->initSetupForm($form);
        foreach ($this->getDefaults() as $k => $v) {
            $form->setDefault($k, $v);
        }
        $form->addSaveCallbacks( function(){}, fn() => self::doCacheCleanup() );
        $event->addForm($form);
    }

    /** You can override it and add elements to create setup form */
    public function initSetupForm(Am_Form_Setup_Theme $form)
    {
    }

    public function getDefaults()
    {
        return [];
    }

    public function getRootDir()
    {
        return AM_THEMES_PATH . '/' . $this->getId();
    }

    // override this in child theme
    function hasParent() { return false; }
    // override this in child theme
    function getParentThemeName() : string { return $this->getId(); }

    /**
     * @param string $relPath like public/css/theme.css , relative to theme or parent theme root folder
     * @return string absolute path to source theme file, either from this or parent theme directory
     **/
    function getFullPath($relPath)
    {
        $fn = $this->getRootDir() . '/'.$relPath;
        if (!$this->hasParent() || file_exists($fn)) {
            return $fn;
        } else {
            return $this->getParentThemeRootDir().'/'.$relPath;
        }
    }

    function getParentThemeRootDir()
    {
        return AM_THEMES_PATH . '/' . $this->getParentThemeName();
    }

    function addViewPath(&$path)
    {
        if ($this->hasParent())
        {
            $path[] = $this->getParentThemeRootDir();
        }
        $path[] = $this->getRootDir();
    }

    /**
     * Unified way for theme to report required HTML includes for fonts
     *
     */
    public function themeRefsFonts(Am_Html_Refs $refs, $view) {
        if ($ff = $this->getConfig('font_family')) {
            if ($fr = self::webFonts()[$ff] ?? null ) {
                $refs->headAppend( Am_Html_Ref::cssUrl('fonts-gstatic', "https://fonts.gstatic.com", ['rel' => 'preconnect', 'crossorigin' => 'anonymous']) );
                $refs->headAppend( Am_Html_Ref::cssUrl('font-gstatic-' . $ff, $fr['cssUrl']) );
            }
        }
    }

    private $_theme_version = null;
    /* must return unique value for current config and file version? */
    public function getThemeVersion() : string {
        if (!$this->_theme_version)
            $this->_theme_version = md5(AM_VERSION_HASH . $this->getConfig('version') ?? '');
        return $this->_theme_version;
    }

    /**
     * This function will parse file, saved parsed version to data/public and return url of cached file
     * If it is impossible, it will return url to public.php to get it parsed online
     *
     * Delete cached file on version / config change to get it recreated
     *
     * BEWARE! full $path must be VaLIDATED before use to make sure it is a theme file !!!!!!
     * $path must be validated that it is inside theme, and that it is a parseable file !!!!
     *
     * @param string $path
     * @param string $cacheIdTpl
     * @return string
     */
    protected function urlCachedPublicWithVars(string $path, string $fn, ?string $compatFnRelToDataPublic = null) : string {
        $cacheId = sprintf('%s%s-%s-%s',
            self::THEME_CACHED_FILE_PREFIX,
            $this->getId(), $this->getThemeVersion(), $fn
        );

        $cacheFn = $this->getDi()->public_dir . '/' . $cacheId;
        $cacheUrl = $this->getDi()->url('data/public') . '/' . $cacheId;

        if (!file_exists($cacheFn)) {
            $parsed = $this->_parseWithVars($path);
            file_put_contents($cacheFn, $parsed);
            if ($compatFnRelToDataPublic !== null) { // to do not break compatibility with old users saved static links to css
                $compatFn = $this->getDi()->public_dir . '/' . $compatFnRelToDataPublic;
                if (!file_exists(dirname($compatFn)))
                    mkdir(dirname($compatFn), 0775, true);
                file_put_contents($compatFn, $parsed);
            }
        }
        if (file_exists($cacheFn)) {
            return $cacheUrl;
        } else { // last resort if we cannot write cache to data/public/
            return $this->urlPublicWithVars(self::THEME_CSS ) . '?' . $this->getThemeVersion() ;
        }
    }

    /** normally you only need to override this function in your theme */
    public function themeRefsTheme(Am_Html_Refs $refs, $view) {
        if (file_exists($this->getFullPath('public/css/theme.css'))) {
            $_ = $this->getThemeVersion();
            if (!in_array(self::THEME_CSS, $this->publicWithVars)) {
                // if file does not have vars, we do not worry about caching
                $refs->headAppend( Am_Html_Ref::cssUrl('theme-css',
                    $view->_scriptCss('theme.css')) ); // view->pathToUrl('public/css/' . $nn)
            } else {
                $path = $this->getFullPath(self::PUBLIC_THEME_CSS);
                if (file_exists($path)) {
                    $url = $this->urlCachedPublicWithVars($path, 'theme.css',
                        $this->getId() . '/theme.css');
                    $refs->headAppend( Am_Html_Ref::cssUrl('theme-css', $url) );
                }
            }
        }
    }

    /**
     * "Standard" amember CSS includes, normally it is same for all themes
     * moved here from Am_View->printLayoutHead()
     */
    public function themeRefsStandard(Am_Html_Refs $refs, $view) {
        $t = "?" . AM_VERSION_HASH;
        if ( $refs->context() === Am_Html_Refs::CTX_VIEW && $this->needReset ) {
            $refs->headAppend( Am_Html_Ref::cssUrl('reset-css', $view->_scriptCss('reset.css').$t) );
        }
        $refs->headAppend( Am_Html_Ref::cssUrl('amember-css', $view->_scriptCss('amember.css').$t) );
        if (!defined('AM_USE_NEW_CSS')) { // support old .grid .row .error and so on
            $refs->headAppend( Am_Html_Ref::cssUrl('compat-css', $view->_scriptCss('compat.css').$t) );
        }
        $refs->headPrepend( Am_Html_Ref::cssUrl('font-awesome-all', "https://use.fontawesome.com/releases/v6.5.1/css/all.css",
            ['media' => 'screen']
        )->anon() );
    }

    /**
     * Unified way for theme to report required HTML includes
     * @param Am_Html_Refs $refs
     * @param $view Am_View
     * @return void
     */
    public function htmlRefs(Am_Html_Refs $refs, $view) {
        $this->themeRefsFonts($refs, $view);
        $this->themeRefsStandard($refs, $view);
        $this->themeRefsTheme($refs, $view);
    }

    public function printLayoutHead(Am_View $view)
    {
        // this function kept here for compat only, use themeRefs instead
    }

    function urlPublicWithVars($relPath)
    {
        return $this->getDi()->url('public/theme/' . $relPath, false);
    }

    function parsePublicWithVars($relPath)
    {
        if (!in_array($relPath, $this->publicWithVars)) {
            amDie("That files is not allowed to open via this URL");
        }

        $f = $this->getFullPath('public/' . $relPath);

        if (!file_exists($f)) {
            amDie("Could not find file [" . htmlentities($relPath, ENT_QUOTES, 'UTF-8') . "]");
        }

        return $this->_parseWithVars($f);
    }

    /** BEWARE file name MUST BE verified or better FIXED before use this function !!!! */
    protected function _parseWithVars(string $absPath) : string
    {
        // we probably do not need simplemtemplate here, kept for compat
        $tpl = new Am_SimpleTemplate();
        $tpl->assign($this->config);
        $_ = $tpl->render(file_get_contents($absPath));
        //
        return str_replace(self::CSS_VARS_PLACEHOLDER, $this->renderCssVariables(), $_);
    }

    function getCssVariables() : array
    {
        $ret = [];
        $conf = array_merge($this->getDefaults(), $this->getConfig());
        foreach ($conf as $k => $v) {
            if (($k[0] == '_') || empty($v) || !is_scalar($v)) continue;
            $ret[ '--am_' . $k ] = $v;
            if (preg_match('#_(size|radius|width)$#', $k)) {
                // may be 'px' or 'em' is already added
                $ret['--am_' . $k . "_px"] = is_numeric($v) ? ($v . "px") : $v;
            }
        }
        return $ret;
    }

    function renderCssVariables() : string
    {
        $out = "";
        foreach ($this->getCssVariables() as $k => $v) $out .= "$k: $v;\n";
        return $out;
    }

    function getNavigation($id)
    {
        $n = new Am_Navigation_User();
        $n->addMenuPages($id);
        return $n;
    }

    function getFontOptions()
    {
        return array_map(fn($x) => $x['name'], self::webFonts());
    }

    /** @return array<array{ name: string, cssUrl: string }> */
    static public function webFonts() : array {
        return [
            'Tahoma' => ['name' => 'Tahoma', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Tahoma:400,700' ],
            'Arial' => ['name' => 'Arial', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Arial:400,700' ],
            'Times' => ['name' => 'Times', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Times:400,700' ],
            'Helvetica' => ['name' => 'Helvetica', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Helvetica:400,700' ],
            'Georgia' => ['name' => 'Georgia', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Georgia:400,700' ],
            'Roboto' => ['name' => 'Roboto', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Roboto:400,700' ],
            'Poppins' => ['name' => 'Poppins', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Poppins:300,700' ],
            'Oxygen' => ['name' => 'Oxygen', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Oxygen:400,700' ],
            'Open Sans' => ['name' => 'Open Sans', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Open Sans:400,700' ],
            'Hind' => ['name' => 'Hind', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Hind:400,700' ],
            'Rajdhani' => ['name' => 'Rajdhani', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Rajdhani:400,700' ],
            'Nunito' => ['name' => 'Nunito', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Nunito:400,700' ],
            'Raleway' => ['name' => 'Raleway', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Raleway:400,700' ],
            'Arsenal' => ['name' => 'Arsenal', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Arsenal:400,700' ],
            'Josefin Sans' => ['name' => 'Josefin Sans', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Josefin Sans:400,700' ],
            'Lato' => ['name' => 'Lato', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Lato:400,700' ],
            'Jost' => ['name' => 'Jost', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Jost:400,700' ],
            'Karla' => ['name' => 'Karla', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Karla:400,700' ],
            'Urbanist' => ['name' => 'Urbanist', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Urbanist:400,700' ],
            'Oxanium' => ['name' => 'Oxanium', 'cssUrl' => 'https://fonts.googleapis.com/css?family=Oxanium:400,700' ],
        ];
    }

    /* remove all cached files, files will be recreated on first request */
    static function doCacheCleanup() {
        $dirname = Am_Di::getInstance()->public_dir;
        $d = opendir($dirname);
        if (!$d)
            return;
        while ($f = @readdir($d))
        {
            if ( substr($f, 0, strlen(self::THEME_CACHED_FILE_PREFIX)) !== self::THEME_CACHED_FILE_PREFIX )
                continue;
            if (!is_file($f))
                continue;
            @unlink("$dirname/$f");
        }
        closedir($d);
    }
}

class Am_Theme_Default extends Am_Theme
{
    public function initSetupForm(Am_Form_Setup_Theme $form)
    {
        $form->addUpload('header_logo', null, ['prefix' => 'theme-default'])
            ->setLabel(___("Header Logo\n" .
                'keep it empty for default value'))->default = '';

        $g = $form->addGroup(null, ['id' => 'logo-link-group'])
            ->setLabel(___('Add hyperlink for Logo'));
        $g->setSeparator(' ');
        $g->addAdvCheckbox('logo_link');
        $g->addText('home_url', ['style' => 'width:80%', 'placeholder' => $this->getDi()->config->get('root_url')], ['prefix' => 'theme-default'])
            ->default = '';

        $form->addScript()
            ->setScript(<<<CUT
jQuery(function($){
    $('[type=checkbox][name$=logo_link]').change(function(){
        $(this).nextAll().toggle(this.checked);
    }).change();
});
CUT
            );

        $form->addHtmlEditor('header', null, ['showInPopup' => true])
            ->setLabel(___("Header\nthis content will be included to header"))
            ->setMceOptions([
                'placeholder_items' => [
                ['Current Year', '%year%'],
                ['Site Title', '%site_title%'],
                ]
            ])->default = '';
        $form->addHtmlEditor('footer', null, ['showInPopup' => true])
            ->setLabel(___("Footer\nthis content will be included to footer"))
            ->setMceOptions([
                'placeholder_items' => [
                ['Current Year', '%year%'],
                ['Site Title', '%site_title%'],
                ]
            ])->default = '';
        $form->addAdvCheckbox('gravatar')
            ->setLabel('User Gravatar in user identity block');
        $form->addSaveCallbacks([$this, 'moveLogoFile'], null);
    }

    function moveLogoFile(Am_Config $before, Am_Config $after)
    {
        $this->moveFile($before, $after, 'header_logo', 'header_path');
    }

    function moveFile(Am_Config $before, Am_Config $after, $nameBefore, $nameAfter)
    {
        $t_id = "themes.{$this->getId()}.{$nameBefore}";
        $t_path = "themes.{$this->getId()}.{$nameAfter}";
        if (!$after->get($t_id)) {
            $after->set($t_path, null);
        } elseif ( ($after->get($t_id) && !$after->get($t_path)) ||
            ($after->get($t_id) && $after->get($t_id) != $before->get($t_id))) {

            $upload = $this->getDi()->uploadTable->load($after->get($t_id));
            switch ($upload->getType())
            {
                case 'image/gif' :
                    $ext = 'gif';
                    break;
                case 'image/png' :
                    $ext = 'png';
                    break;
                case 'image/jpeg' :
                    $ext = 'jpg';
                    break;
                case 'image/svg+xml' :
                    $ext = 'svg';
                    break;
                default :
                    throw new Am_Exception_InputError(sprintf('Unknown MIME type [%s]', $upload->getType()));
            }

            $name = str_replace(".{$upload->prefix}.", '', $upload->path);
            $filename = $upload->getFullPath();

            $newName =  $name . '.' . $ext;
            $newFilename = $this->getDi()->public_dir . '/' . $newName;
            copy($filename, $newFilename);
            $after->set($t_path, $newName);
        }
    }

    function onInitBlocks(Am_Event $e)
    {
        if ($this->getConfig('gravatar')) {
            $e->getBlocks()->remove('member-identity');
            $e->getBlocks()->add('member/identity', new Am_Block_Base(null, 'member-identity-gravatar', null, function(Am_View $v){
                $login = Am_Html::escape($v->di->user->login);
                $url = Am_Di::getInstance()->url('logout');
                $url_label = Am_Html::escape(___('Logout'));
                $avatar_url = Am_Html::escape('//www.gravatar.com/avatar/' . md5(strtolower(trim($v->di->user->email))) . '?s=24&d=mm');
                return <<<CUT
<div class="am-user-identity-block-avatar">
    <div class="am-user-identity-block-avatar-pic">
        <img src="$avatar_url" />
    </div>
    <span class="am-user-identity-block_login">$login</span> <a href="$url">$url_label</a>
</div>
CUT;
            }));
        }
    }

    function onBeforeRender(Am_Event $e)
    {
        $e->getView()->theme_logo_url = $this->logoUrl($e->getView());
    }

    function logoUrl(Am_View $v)
    {
        if ($path = $this->getConfig('header_path')) {
            return $this->getDi()->url("data/public/{$path}", false);
        } elseif (($logo_id = $this->getConfig('header_logo')) && ($upload = $this->getDi()->uploadTable->load($logo_id, false))) {
            return $this->getDi()->url('upload/get/' . preg_replace('/^\./', '', $upload->path), false);
        } else {
            return $v->_scriptImg('/header-logo.png');
        }
    }

    public function getDefaults()
    {
        return parent::getDefaults() + [
                'logo_link' => 1
            ];
    }
}