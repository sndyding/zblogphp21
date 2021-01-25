<?php
/**
 * 路由和控制器相关函数.
 */

if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

//###############################################################################################################

/**
 * 根据url路由规则显示页面的主路由器函数.
 *
 *
 * @api Filter_Plugin_ViewAuto_Begin
 * @api Filter_Plugin_ViewAuto_End
 *
 * @throws Exception
 *
 * @return null|string
 */
function ViewAuto()
{
    global $zbp;

    $inpurl = $zbp->currenturl;

    $url = GetValueInArray(explode('?', $inpurl), '0');

    if ($zbp->cookiespath === substr($url, 0, strlen($zbp->cookiespath))) {
        $url = substr($url, strlen($zbp->cookiespath));
    }

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewAuto_Begin'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname($inpurl, $url);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    $url = urldecode($url);

    //匹配动态路由（某些情况下，在伪静开启时匹配但不输出内容，如果是符合条件就可以跳转）
    foreach ($zbp->routes['active'] as $key => $route) {
        $prefix = GetValueInArray($route, 'prefix', '');
        $prefix = empty($prefix) ? '' : ($prefix . '/');
        if (($url == $prefix . '') || ($url == $prefix . 'index.php')) {
            $b = ViewAuto_Check_Get_And_Not_Get_And_Must_Get(GetValueInArray($route, 'get', array()), GetValueInArray($route, 'not_get', array()), GetValueInArray($route, 'must_get', array()));
            $b = $b && ViewAuto_Check_Request_Method(GetValueInArray($route, 'request_method', ''));
            //如果条件符合就组合参数数组并调用函数
            if ($b) {
                $array = array();
                ViewAuto_Process_Args_get($array, GetValueInArray($route, 'args_get', array()));
                ViewAuto_Process_Args_with($array, GetValueInArray($route, 'args_with', array()), $route);
                $b_redirect = GetValueInArray($route, 'to_permalink', false);
                $b_redirect = ($zbp->option['ZC_STATIC_MODE'] == 'REWRITE') && $b_redirect;
                $b_redirect = ViewAuto_Check_CheckRegex2UrlIsNotEmpty($route) && $b_redirect;
                if ($b_redirect) {
                    $array['canceldisplay'] = true;
                }
                $result = ViewAuto_Call_Auto($route, $array);
                if ($result == true) {
                    $template = &$zbp->template;
                    //如果开启伪静且$b_redirect，那么通过原动态访问的会跳转至$template->GetTags('url')
                    if ($b_redirect) {
                        Redirect($template->GetTags('url'));
                    }
                    return;
                }
            }
        }
    }


    //匹配伪静路由
    foreach ($zbp->routes['rewrite'] as $key => $route) {
        //$match_with_page 默认匹配1次 (true)，有page参数可以匹配2次 [false=(remove page), true=(keep page)]
        $parameters = array();
        $match_with_page = array();
        ViewAuto_Get_Parameters_And_Match_with_page($route, $parameters, $match_with_page);

        foreach ($match_with_page as $match_with_page_key => $match_with_page_value) {
            $b = ViewAuto_Check_Get_And_Not_Get_And_Must_Get(GetValueInArray($route, 'get', array()), GetValueInArray($route, 'not_get', array()), GetValueInArray($route, 'must_get', array()));
            $b = $b && ViewAuto_Check_Request_Method(GetValueInArray($route, 'request_method', ''));
            //如果直接指定了$route['urlrule_regex']，就不调用UrlRule::OutputUrlRegEx，直接preg_match
            if (isset($route['urlrule_regex']) && trim($route['urlrule_regex']) != '') {
                $r = trim($route['urlrule_regex']);
            } else {
                //$r = UrlRule::OutputUrlRegEx_V2($zbp->GetPostType(0, 'list_urlrule'), 'list', $match_with_page_value);
                $r = UrlRule::OutputUrlRegEx_Route($route, $match_with_page_value);
            }

            $m = array();
            //如果条件符合就组合参数数组并调用函数
            //var_dump($match_with_page_value, $route['urlrule'], $r, $url, $m);//die;
            //var_dump(preg_match($r, $url, $m));
            $b = $b && (($r != '' && preg_match($r, $url, $m) == 1) || ($r == '' && $url == '') || ($r == '' && $url == 'index.php'));
            if ($b) {
                $array = $m;
                ViewAuto_Process_Args($array, $parameters, $m);
                ViewAuto_Process_Args_with($array, GetValueInArray($route, 'args_with', array()), $route);
                //var_dump($match_with_page_value, $route['urlrule'], $r, $url, $m, $array);//die;
                $result = ViewAuto_Call_Auto($route, $array);
                if ($result == true) {
                    return;
                }
            }
        }
    }

    //都不能匹配时，进入一次默认路由
    if ($url == '' || $url == '/' || $url == 'index.php') {
        foreach ($zbp->routes['default'] as $key => $route) {
            $b = ViewAuto_Check_Get_And_Not_Get_And_Must_Get(GetValueInArray($route, 'get', array()), GetValueInArray($route, 'not_get', array()), GetValueInArray($route, 'must_get', array()));
            $b = $b && ViewAuto_Check_Request_Method(GetValueInArray($route, 'request_method', ''));
            if ($b) {
                $array = array();
                ViewAuto_Process_Args_get($array, GetValueInArray($route, 'args_get', array()));
                ViewAuto_Process_Args_with($array, GetValueInArray($route, 'args_with', array()), $route);
                $result = ViewAuto_Call_Auto($route, $array);
                if ($result == true) {
                    return;
                }
            }
        }
    }

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewAuto_End'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname($url);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    $zbp->ShowError(2, __FILE__, __LINE__);

    return false;
}

/**
 * ViewAuto的辅助函数
 */
function ViewAuto_Process_Args(&$array, $parameters, $m)
{
    foreach ($parameters as $key => $value) {
        if (isset($m[(string) $value['name']])) {
            $array[$value['name']] = $m[(string) $value['name']];
            if ($value['alias'] != '') {
                $array[$value['alias']] = $m[(string) $value['name']];
            }
        }
    }
    return $array;
}

/**
 * ViewAuto的辅助函数
 */
function ViewAuto_Process_Args_get(&$array, $args_get)
{
    if (isset($args_get) && is_array($args_get)) {
        foreach ($args_get as $key => $value) {
            if (isset($_GET[$value])) {
                $array[$value] = $_GET[$value];
            } else {
                $array[$value] = null;
            }
        }
    }
    return $array;
}

/**
 * ViewAuto的辅助函数
 */
function ViewAuto_Process_Args_with(&$array, $args_with, $route)
{
    if (isset($args_with) && is_array($args_with)) {
        foreach ($args_with as $key => $value) {
            if (is_integer($key) && is_scalar($value)) {
                if (isset($_GET[$value])) {
                    $array[$value] = $_GET[$value];
                }
                if (isset($route[$value])) {
                    $array[$value] = $route[$value];
                }
            } elseif (is_string($key)) {
                $array[$key] = $value;
            }
        }
    }
    return $array;
}

/**
 * ViewAuto的辅助函数
 */
function ViewAuto_Call_Auto($route, $array)
{
    $function = $route['call'];
    $array['route'] = $route;
    if (strpos($function, '::') !== false) {
        $array = explode('::', $function);
        call_user_func(array($array[0], $array[1]));
    } elseif (strpos($function, '@') !== false) {
        $array = explode('@', $function);
        $object = new $array[0];
        call_user_func(array($object, $array[1]));
    } else {
        return call_user_func($function, $array);
    }
}

/**
 * ViewAuto的辅助函数
 */
function ViewAuto_Check_CheckRegex2UrlIsNotEmpty($route)
{
    $urlrule = GetValueInArray($route, 'urlrule', '');
    if (empty($urlrule)) {
        return false;
    }
    $match_with_page = false;
    if (array_key_exists('page', $_GET)) {
        $match_with_page = true;
    }
    $r = UrlRule::OutputUrlRegEx_Route($route, $match_with_page);
    if ($r != '') {
        return true;
    }
    return false;
}

/**
 * ViewAuto的辅助函数
 */
function ViewAuto_Get_Parameters_And_Match_with_page($route, &$parameters, &$match_with_page)
{
    if (isset($route['args']) && is_array($route['args'])) {
        $parameters = UrlRule::ProcessParameters($route);
    } else {
        $parameters = array();
    }

    $match_with_page = array('keep_page' => true, 'remove_page' => false);
    $haspage = false;
    foreach ($parameters as $key => $value) {
        if ($value['name'] == 'page') {
            $haspage = true;
        }
    }
    if ($haspage == false) {
        unset($match_with_page['remove_page']);
    }

    $only_match_page = GetValueInArray($route, 'only_match_page', false);
    if ($only_match_page == true) {
        unset($match_with_page['remove_page']);
    }
}

/**
 * ViewAuto的辅助函数
 */
function ViewAuto_Check_Request_Method($request_method)
{
    $b = false;
    if (!empty($request_method)) {
        $m = $_SERVER['REQUEST_METHOD'];
        if (is_array($request_method)) {
            foreach ($request_method as $key => $value) {
                if (strcasecmp($value, $m) == 0) {
                    $b = true;
                    break;
                }
            }
        } else {
            if (strcasecmp($request_method, $m) == 0) {
                $b = true;
            }
        }
    } else {
        $b = true;
    }
    return $b;
}

/**
 * ViewAuto的辅助函数
 */
function ViewAuto_Check_Get_And_Not_Get_And_Must_Get($get, $notget, $mustget)
{
    $b = false;
    //检查GET参数是否存在(最少一个或多个存在) OR
    if (!empty($get)) {
        foreach ($get as $key => $value) {
            if (isset($_GET[$value])) {
                $b = true;
                break;
            }
        }
    } else {
        $b = true;
    }
    //检查GET参数是否有不需要的存在(全部不能存在) NOT
    if (!empty($notget)) {
        foreach ($notget as $key => $value) {
            if ((substr($value, 0, 1) == '/' && substr_count($value, '/') > 1) || (substr($value, 0, 1) == '#' && substr_count($value, '#') > 1) || (substr($value, 0, 1) == '~' && substr_count($value, '~') > 1)) {
                foreach ($_GET as $key2 => $value2) {
                    if (preg_match($value, $key2) === 1) {
                        $b = false;
                        return $b;
                    }
                }
            } else {
                if (isset($_GET[$value])) {
                    $b = false;
                    return $b;
                }
            }
        }
    }
    //检查GET参数是否有必须的存在(全部必存在) AND
    if (!empty($mustget)) {
        $c = array();
        foreach ($mustget as $key => $value) {
            if (isset($_GET[$value])) {
                $c[] = true;
            } else {
                $c[] = false;
            }
        }
        if (in_array(false, $c, true) == true) {
            $b = false;
        }
    }
    return $b;
}

/**
 * ViewAuto的辅助函数
 */
function ViewAuto_Process_Pagebar_Replace_Array(&$pagebar, $route, $args)
{
    $args = is_array($args) ? $args : array();
    $array = GetValueInArray($args, 0, array());
    $array = is_array($array) ? $array : array();
    $parameters = UrlRule::ProcessParameters($route);
    $replace = array();

    foreach ($parameters as $key => $value) {
        if (isset($array[$key])) {
            $replace[$value['name']] = $array[$key];
        }
    }
    $rules = &$pagebar->UrlRule->Rules;
    foreach ($replace as $key => $value) {
        if (!isset($rules['{%' . $key . '%}'])) {
            $rules['{%' . $key . '%}'] = $value;
        }
    }
    foreach ($array as $key => $value) {
        if (is_string($key) && is_scalar($value) && !isset($rules['{%' . $key . '%}'])) {
            $rules['{%' . $key . '%}'] = $value;
        }
    }
}

//###############################################################################################################

/**
 * ViewIndex,首页，搜索页，feed页的主函数.
 *
 * @api Filter_Plugin_ViewIndex_Begin
 *
 * @throws Exception
 *
 * @return mixed
 */
function ViewIndex()
{
    global $zbp, $action;

    $url = $zbp->currenturl;

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewIndex_Begin'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname($url);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    switch ($action) {
        case 'feed':
            ViewFeed();
            break;
        case 'search':
            ViewSearch();
            break;
        case 'view':
        case '':
        default:
            ViewAuto();
    }

    return true;
}

/**
 * 显示RSS2Feed.
 *
 * @api Filter_Plugin_ViewFeed_Begin
 */
function ViewFeed()
{
    global $zbp;

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewFeed_Begin'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname();
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    if (!$zbp->CheckRights($GLOBALS['action'])) {
        Http404();
        die;
    }

    $rss2 = new Rss2($zbp->name, $zbp->host, $zbp->subname);

    $w = array(array('=', 'log_Status', 0));

    $postype = (int) GetVars('posttype', 'GET', 0);
    $w = array(array('=', 'log_Type', $postype));
    $actions = $zbp->GetPostType($postype, 'actions');

    if (!$zbp->CheckRights($actions['view'])) {
        Http404();
        die;
    }

    if (GetVars('cate', 'GET') != null) {
        $w[] = array('=', 'log_CateID', (int) GetVars('cate', 'GET'));
    } elseif (GetVars('auth', 'GET') != null) {
        $w[] = array('=', 'log_AuthorID', (int) GetVars('auth', 'GET'));
    } elseif (GetVars('date', 'GET') != null) {
        $d = strtotime(GetVars('date', 'GET'));
        if (strrpos(GetVars('date', 'GET'), '-') !== strpos(GetVars('date', 'GET'), '-')) {
            $w[] = array('BETWEEN', 'log_PostTime', $d, strtotime('+1 day', $d));
        } else {
            $w[] = array('BETWEEN', 'log_PostTime', $d, strtotime('+1 month', $d));
        }
    } elseif (GetVars('tags', 'GET') != null) {
        $w[] = array('LIKE', 'log_Tag', '%{' . (int) GetVars('tags', 'GET') . '}%');
    }

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewFeed_Core'] as $fpname => &$fpsignal) {
        $fpname($w);
    }

    $articles = $zbp->GetPostList(
        '*',
        $w,
        array('log_PostTime' => 'DESC'),
        $zbp->option['ZC_RSS2_COUNT'],
        null
    );

    foreach ($articles as $article) {
        $rss2->addItem($article->Title, $article->Url, ($zbp->option['ZC_RSS_EXPORT_WHOLE'] == true ? $article->Content : $article->Intro), $article->PostTime);
    }

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewFeed_End'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname($rss2);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    header("Content-type:text/xml; Charset=utf-8");

    echo $rss2->saveXML();

    return true;
}

/**
 * 展示搜索结果.
 *
 * @api Filter_Plugin_ViewSearch_Begin
 * @api Filter_Plugin_ViewPost_Template
 *
 * @throws Exception
 *
 * @return mixed
 */
function ViewSearch()
{
    global $zbp;

    $fpargs = call_user_func('func_get_args');
    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewSearch_Begin'] as $fpname => &$fpsignal) {
        $fpreturn = call_user_func_array($fpname, $fpargs);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;
            return $fpreturn;
        }
    }

    $q = GetVars('q', 'GET');
    $page = GetVars('page', 'GET');

    $args = GetValueInArray($fpargs, 0, null);
    if (is_array($args)) {
        $canceldisplay = GetValueInArray($args, 'canceldisplay', false);
        $posttype = GetValueInArray($args, 'posttype', 0);
        $q = GetValueInArray($args, 'q', '');
        if (isset($args['search']) && $args['search']) {
            $q = $args['search'];
        }
        $page = GetValueInArray($args, 'page', 0);
        $route = GetValueInArray($args, 'route', array());
        $disablebot = GetValueInArray($args, 'disablebot', true);
    } else {
        $canceldisplay = false;
        $posttype = 0;
        $route = array('urlrule' => $zbp->option['ZC_SEARCH_REGEX']);
        $disablebot = true;
    }

    $q = trim(htmlspecialchars($q));
    $page = (int) $page == 0 ? 1 : (int) $page;

    $w = array();
    $w[] = array('=', 'log_Type', $posttype);

    //没有权限就搜索空的
    $actions = $zbp->GetPostType($posttype, 'actions');
    if (!$zbp->CheckRights($actions['search'])) {
        $w[] = array('=', 'log_ID', 0);
    }

    $article = new Post();
    $article->ID = 0;
    $article->Title = $zbp->langs->msg->search . '&nbsp;&quot;<span>' . $q . '</span>&quot;';
    $article->IsLock = true;
    $article->Type = $posttype;

    if ($q) {
        $w[] = array('search', 'log_Content', 'log_Intro', 'log_Title', $q);
    } else {
        $w[] = array('=', 'log_ID', 0);
    }

    if (!($zbp->CheckRights($article->TypeActions['all']))) {
        $w[] = array('=', 'log_Status', 0);
    }
    $order = array('log_PostTime' => 'DESC');

    $pagebar = new Pagebar($route);
    $pagebar->PageCount = $zbp->searchcount;
    $pagebar->PageNow = $page;
    $pagebar->PageBarCount = $zbp->pagebarcount;
    $pagebar->UrlRule->Rules['{%page%}'] = $page;
    $pagebar->UrlRule->Rules['{%q%}'] = rawurlencode($q);
    $pagebar->UrlRule->Rules['{%search%}'] = rawurlencode($q);
    ViewAuto_Process_Pagebar_Replace_Array($pagebar, $route, $fpargs);

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewSearch_Core'] as $fpname => &$fpsignal) {
        $fpname($q, $page, $w, $pagebar, $order);
    }

    $array = $zbp->GetPostList(
        '',
        $w,
        $order,
        array(($pagebar->PageNow - 1) * $pagebar->PageCount, $pagebar->PageCount),
        array('pagebar' => $pagebar)
    );

    $results = array();

    foreach ($array as $a) {
        $r = new Post();
        $r->LoadInfoByDataArray($a->GetData());
        $article->Content .= '<p><a href="' . $a->Url . '">' . str_ireplace($q, '<strong>' . $q . '</strong>', $a->Title) . '</a><br/>';
        $s = strip_tags($a->Intro) . ' ' . strip_tags($a->Content);
        $i = Zbp_Stripos($s, $q, 0);
        if ($i !== false) {
            if ($i > 50) {
                $t = SubStrUTF8_Start($s, ($i - 50), 100);
            } else {
                $t = SubStrUTF8_Start($s, 0, 100);
            }
            $article->Content .= str_ireplace($q, '<strong>' . $q . '</strong>', $t) . '<br/>';
            $r->Intro = str_ireplace($q, '<strong>' . $q . '</strong>', $t);
            $r->Content = $a->Content;
        } else {
            $s = strip_tags($a->Title);
            $i = Zbp_Strpos($s, $q, 0);
            if ($i > 50) {
                $t = SubStrUTF8_Start($s, ($i - 50), 100);
            } else {
                $t = SubStrUTF8_Start($s, 0, 100);
            }
            $article->Content .= str_replace($q, '<strong>' . $q . '</strong>', $t) . '<br/>';
            $r->Intro = str_replace($q, '<strong>' . $q . '</strong>', $t);
            $r->Content = $a->Content;
        }
        $r->Title = str_replace($q, '<strong>' . $q . '</strong>', $r->Title);
        $article->Content .= '<a href="' . $a->Url . '">' . $a->Url . '</a><br/></p>';
        $results[] = $r;
    }

    if ($disablebot) {
        $zbp->header .= '    <meta name="robots" content="noindex,nofollow,noarchive" />' . "\r\n";
    }
    $zbp->template->SetTags('title', str_replace(array('<span>', '</span>'), '', $article->Title));
    $zbp->template->SetTags('article', $article);
    $zbp->template->SetTags('articles', $results);
    $zbp->template->SetTags('search', $q);
    $zbp->template->SetTags('page', $page);
    $zbp->template->SetTags('pagebar', $pagebar);
    $zbp->template->SetTags('comments', array());
    $zbp->template->SetTags('issearch', true);
    $zbp->template->SetTags('posttype', $posttype);
    if (is_object($pagebar) && isset($pagebar->buttons[$pagebar->PageNow])) {
        $zbp->template->SetTags('url', $pagebar->buttons[$pagebar->PageNow]);
    } else {
        $zbp->template->SetTags('url', $zbp->host);
    }
    $zbp->template->SetTags('args', $fpargs);
    $zbp->template->SetTags('route', $route);

    //1.6统一改为search模式
    $zbp->template->SetTags('type', 'search');
    //1.7指定搜索模板为优先为search或是index
    if ($zbp->template->HasTemplate($zbp->GetPostType($posttype, 'search_template'))) {
        $zbp->template->SetTemplate($zbp->GetPostType($posttype, 'search_template'));
    } else {
        $zbp->template->SetTemplate($zbp->GetPostType($posttype, 'list_template'));
    }

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewSearch_Template'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname($zbp->template);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    if ($canceldisplay == false) {
        $zbp->template->Display();
    }

    return true;
}

//###############################################################################################################

/**
 * 显示列表页面.
 *
 * @param int   $page (1.7起做为主要array型参数，后续的都作废了)
 * @param mixed $cate           分类 id或alias
 * @param mixed $auth           作者 id或alias
 * @param mixed $date           日期
 * @param mixed $tags           tags id或alias
 * @param mixed $isrewrite      是否启用urlrewrite
 * @param array $object         把1.7里新增array型参数传给旧版本的接口
 *
 * @api Filter_Plugin_ViewList_Begin
 * @api Filter_Plugin_ViewList_Begin_V2
 * @api Filter_Plugin_ViewList_Template
 *
 * @throws Exception
 *
 * @return string
 */
function ViewList($page = null, $cate = null, $auth = null, $date = null, $tags = null, $isrewrite = false, $object = array())
{
    global $zbp;

    $fpargs_v1 = array();
    $fpargs_v2 = call_user_func('func_get_args');
    $fpargs = &$fpargs_v2;

    //新版本的函数V2 (v2版本传入的第一个参数是array且只传一个array)
    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewList_Begin_V2'] as $fpname => &$fpsignal) {
        $fpreturn = call_user_func_array($fpname, $fpargs_v2);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    //修正首个参数使用array而不传入后续参数的情况
    if (is_array($page)) {
        $object = $page;
        $isrewrite = true;
        $cate = GetValueInArray($page, 'cate', null);
        $auth = GetValueInArray($page, 'auth', null);
        $date = GetValueInArray($page, 'date', null);
        $tags = GetValueInArray($page, 'tags', null);
        $posttype = GetValueInArray($page, 'posttype', 0);
        $canceldisplay = GetValueInArray($page, 'canceldisplay', false);
        $route = GetValueInArray($page, 'route', array());
        $page = GetValueInArray($page, 'page', null);
    } else {
        $object = array();
        $posttype = 0;
        $canceldisplay = false;
    }

    //处理上一版本兼容性的问题
    $fpargs_v1 = array($page, $cate, $auth, $date, $tags, $isrewrite, $object);

    //老版本的兼容接口
    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewList_Begin'] as $fpname => &$fpsignal) {
        $fpreturn = call_user_func_array($fpname, $fpargs_v1);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    $type = 'index';
    if ($cate !== null) {
        $type = 'category';
    }

    if ($auth !== null) {
        $type = 'author';
    }

    if ($date !== null) {
        $type = 'date';
    }

    if ($tags !== null) {
        $type = 'tag';
    }

    $category = null;
    $author = null;
    $datetime = null;
    $tag = null;

    $w = array();
    $w[] = array('=', 'log_Type', $posttype);
    $w[] = array('=', 'log_Status', 0);
    $w[] = array('=', 'log_IsTop', 0);

    $page = (int) $page == 0 ? 1 : (int) $page;

    $articles = array();
    $articles_top = array();

    switch ($type) {
            //#######################################################################################################
        case 'index':
            $pagebar = new Pagebar($route);
            if (0 == $posttype) {
                $pagebar->Count = $zbp->cache->normal_article_nums;
            }
            $template = $zbp->GetPostType($posttype, 'list_template');
            if ($page == 1) {
                $zbp->title = $zbp->subname;
            } else {
                $zbp->title = str_replace('%num%', $page, $zbp->lang['msg']['number_page']);
            }
            break;
            //#######################################################################################################
        case 'category':
            $pagebar = new Pagebar($route);
            $category = new Category();

            if (!is_array($cate)) {
                $cateId = $cate;
                $cate = array();
                if (strpos($zbp->option['ZC_CATEGORY_REGEX'], '{%id%}') !== false) {
                    $cate['id'] = $cateId;
                }
                if (strpos($zbp->option['ZC_CATEGORY_REGEX'], '{%alias%}') !== false) {
                    $cate['alias'] = $cateId;
                }
            }
            if (isset($cate['id'])) {
                $category = $zbp->GetCategoryByID($cate['id']);
            } else {
                $category = $zbp->GetCategoryByAlias($cate['alias'], $posttype);
                if ($category->ID == '') {
                    $category = $zbp->GetCategoryByAliasOrName($cate['alias'], $posttype);
                }
            }

            if ($category->ID == '') {
                if (!empty($route) || $isrewrite == true) {
                    return false;
                }

                $zbp->ShowError(2, __FILE__, __LINE__);
            }
            if ($page == 1) {
                $zbp->title = $category->Name;
            } else {
                $zbp->title = $category->Name . ' ' . str_replace('%num%', $page, $zbp->lang['msg']['number_page']);
            }
            $template = $category->Template;

            if (!$zbp->option['ZC_DISPLAY_SUBCATEGORYS']) {
                $w[] = array('=', 'log_CateID', $category->ID);
                $pagebar->Count = $category->Count;
            } else {
                $arysubcate = array();
                $arysubcate[] = array('log_CateID', $category->ID);
                if (isset($zbp->categories[$category->ID])) {
                    foreach ($zbp->categories[$category->ID]->ChildrenCategories as $subcate) {
                        $arysubcate[] = array('log_CateID', $subcate->ID);
                    }
                }
                $w[] = array('array', $arysubcate);
            }

            $pagebar->UrlRule->Rules['{%id%}'] = $category->ID;
            $pagebar->UrlRule->Rules['{%alias%}'] = $category->Alias == '' ? rawurlencode($category->Name) : $category->Alias;
            $pagebar->UrlRule->RulesObject = $category;
            break;
            //#######################################################################################################
        case 'author':
            $pagebar = new Pagebar($route);
            $author = new Member();

            if (!is_array($auth)) {
                $authId = $auth;
                $auth = array();
                if (strpos($zbp->option['ZC_AUTHOR_REGEX'], '{%id%}') !== false) {
                    $auth['id'] = $authId;
                }
                if (strpos($zbp->option['ZC_AUTHOR_REGEX'], '{%alias%}') !== false) {
                    $auth['alias'] = $authId;
                }
            }
            if (isset($auth['id'])) {
                /* @var Member $author */
                $author = $zbp->GetMemberByID($auth['id']);
            } else {
                /* @var Member $author */
                $author = $zbp->GetMemberByNameOrAlias($auth['alias']);
            }

            if ($author->ID == '') {
                if (!empty($route) || $isrewrite == true) {
                    return false;
                }

                $zbp->ShowError(2, __FILE__, __LINE__);
            }
            if ($page == 1) {
                $zbp->title = $author->StaticName;
            } else {
                $zbp->title = $author->StaticName . ' ' . str_replace('%num%', $page, $zbp->lang['msg']['number_page']);
            }
            $template = $author->Template;
            $w[] = array('=', 'log_AuthorID', $author->ID);
            //$pagebar->Count = $author->Articles;
            $pagebar->UrlRule->Rules['{%id%}'] = $author->ID;
            $pagebar->UrlRule->Rules['{%alias%}'] = $author->Alias == '' ? rawurlencode($author->Name) : $author->Alias;
            $pagebar->UrlRule->RulesObject = $author;
            break;
            //#######################################################################################################
        case 'date':
            $pagebar = new Pagebar($route);

            if (!is_array($date)) {
                $datetime = $date;
            } else {
                $datetime = $date['date'];
            }

            $dateregex_ymd = '/[0-9]{1,4}-[0-9]{1,2}-[0-9]{1,2}/i';
            $dateregex_ym = '/[0-9]{1,4}-[0-9]{1,2}/i';

            if (preg_match($dateregex_ymd, $datetime) == 0 && preg_match($dateregex_ym, $datetime) == 0) {
                return false;
            }
            $datetime_txt = $datetime;
            $datetime = strtotime($datetime);
            if ($datetime == false) {
                return false;
            }

            if (preg_match($dateregex_ymd, $datetime_txt) != 0 && isset($zbp->lang['msg']['year_month_day'])) {
                $datetitle = str_replace(array('%y%', '%m%', '%d%'), array(date('Y', $datetime), date('n', $datetime), date('j', $datetime)), $zbp->lang['msg']['year_month_day']);
            } else {
                $datetitle = str_replace(array('%y%', '%m%'), array(date('Y', $datetime), date('n', $datetime)), $zbp->lang['msg']['year_month']);
            }

            if ($page == 1) {
                $zbp->title = $datetitle;
            } else {
                $zbp->title = $datetitle . ' ' . str_replace('%num%', $page, $zbp->lang['msg']['number_page']);
            }

            $zbp->modulesbyfilename['calendar']->Content = ModuleBuilder::Calendar(date('Y', $datetime) . '-' . date('n', $datetime));

            $template = $zbp->GetPostType($posttype, 'list_template');

            if (preg_match($dateregex_ymd, $datetime_txt) != 0) {
                $w[] = array('BETWEEN', 'log_PostTime', $datetime, strtotime('+1 day', $datetime));
                $pagebar->UrlRule->Rules['{%date%}'] = date('Y-n-j', $datetime);
            } else {
                $w[] = array('BETWEEN', 'log_PostTime', $datetime, strtotime('+1 month', $datetime));
                $pagebar->UrlRule->Rules['{%date%}'] = date('Y-n', $datetime);
            }

            $pagebar->UrlRule->RulesObject = new ZbpDate($datetime);
            $datetime = Metas::ConvertArray(getdate($datetime));
            break;
            //#######################################################################################################
        case 'tag':
            $pagebar = new Pagebar($route);
            $tag = new Tag();

            if (!is_array($tags)) {
                $tagId = $tags;
                $tags = array();
                if (strpos($zbp->option['ZC_TAGS_REGEX'], '{%id%}') !== false) {
                    $tags['id'] = $tagId;
                }
                if (strpos($zbp->option['ZC_TAGS_REGEX'], '{%alias%}') !== false) {
                    $tags['alias'] = $tagId;
                }
            }
            if (isset($tags['id'])) {
                $tag = $zbp->GetTagByID($tags['id']);
            } else {
                $tag = $zbp->GetTagByAliasOrName($tags['alias'], $posttype);
            }

            if ($tag->ID == 0) {
                if (!empty($route) || $isrewrite == true) {
                    return false;
                }

                $zbp->ShowError(2, __FILE__, __LINE__);
            }

            if ($page == 1) {
                $zbp->title = $tag->Name;
            } else {
                $zbp->title = $tag->Name . ' ' . str_replace('%num%', $page, $zbp->lang['msg']['number_page']);
            }

            $template = $tag->Template;
            $w[] = array('LIKE', 'log_Tag', '%{' . $tag->ID . '}%');
            $pagebar->UrlRule->Rules['{%id%}'] = $tag->ID;
            $pagebar->UrlRule->Rules['{%alias%}'] = $tag->Alias == '' ? rawurlencode($tag->Name) : $tag->Alias;
            $pagebar->UrlRule->RulesObject = $tag;
            break;
        default:
            throw new Exception('Unknown type');
    }

    $pagebar->PageCount = $zbp->displaycount;
    $pagebar->PageNow = $page;
    $pagebar->PageBarCount = $zbp->pagebarcount;
    $pagebar->UrlRule->Rules['{%page%}'] = $page;

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewList_Core'] as $fpname => &$fpsignal) {
        $fpname($type, $page, $category, $author, $datetime, $tag, $w, $pagebar, $template);
    }

    if ($zbp->option['ZC_LISTONTOP_TURNOFF'] == false) {
        $articles_top_notorder = $zbp->GetTopPost($posttype);
        foreach ($articles_top_notorder as $articles_top_notorder_post) {
            if ($articles_top_notorder_post->TopType == 'global') {
                $articles_top[] = $articles_top_notorder_post;
            }
        }
        if ($type == 'index' && $page == 1) {
            foreach ($articles_top_notorder as $articles_top_notorder_post) {
                if ($articles_top_notorder_post->TopType == 'index') {
                    $articles_top[] = $articles_top_notorder_post;
                }
            }
        }
        if ($type == 'category' && $page == 1) {
            foreach ($articles_top_notorder as $articles_top_notorder_post) {
                if ($articles_top_notorder_post->TopType == 'category' && $articles_top_notorder_post->CateID == $category->ID) {
                    $articles_top[] = $articles_top_notorder_post;
                }
            }
        }
    }

    $select = '';
    $order = array('log_PostTime' => 'DESC');
    $limit = array(($pagebar->PageNow - 1) * $pagebar->PageCount, $pagebar->PageCount);
    $option = array('pagebar' => $pagebar);
    ViewAuto_Process_Pagebar_Replace_Array($pagebar, $route, $fpargs);

    foreach ($GLOBALS['hooks']['Filter_Plugin_LargeData_Article'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname($select, $w, $order, $limit, $option, $type);
    }

    $articles = $zbp->GetPostList(
        $select,
        $w,
        $order,
        $limit,
        $option
    );

    if (count($articles) <= 0 && $page > 1) {
        $zbp->ShowError(2, __FILE__, __LINE__);
    }

    $articles = array_merge($articles_top, $articles);

    $tagstring = null;
    foreach ($articles as $key => $article) {
        $tagstring .= $article->Tag;
    }
    $zbp->LoadTagsByIDString($tagstring);

    foreach ($articles as $key => &$article) {
        $classname = $zbp->GetPostType($posttype, 'classname');
        if (strcasecmp(get_class($article), $classname) != 0) {
            $newarticle = $article->Cloned($classname);
            $article = $newarticle;
            $zbp->AddPostCache($article);
        }
    }

    $zbp->LoadMembersInList($articles);

    $zbp->template->SetTags('title', $zbp->title);
    $zbp->template->SetTags('articles', $articles);
    if ($pagebar->PageAll == 0) {
        $pagebar = null;
    }

    $zbp->template->SetTags('posttype', $posttype);
    $zbp->template->SetTags('pagebar', $pagebar);
    $zbp->template->SetTags('type', $type);
    $zbp->template->SetTags('page', $page);

    $zbp->template->SetTags('date', $datetime);
    $zbp->template->SetTags('tag', $tag);
    $zbp->template->SetTags('author', $author);
    $zbp->template->SetTags('category', $category);

    if (is_object($pagebar) && isset($pagebar->buttons[$pagebar->PageNow])) {
        $zbp->template->SetTags('url', $pagebar->buttons[$pagebar->PageNow]);
    } else {
        $zbp->template->SetTags('url', $zbp->host);
    }
    $zbp->template->SetTags('args', $fpargs);
    $zbp->template->SetTags('route', $route);

    if ($zbp->template->hasTemplate($template)) {
        $zbp->template->SetTemplate($template);
    } else {
        $zbp->template->SetTemplate('index');
    }

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewList_Template'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname($zbp->template);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    if ($canceldisplay == false) {
        $zbp->template->Display();
    }

    return true;
}

/**
 * 显示文章.
 *
 * @param array|int|string $id         文章ID/ ID/别名对象 (1.7起做为主要array型参数，后续的都作废了)
 * @param string           $alias     （如果有的话）文章别名
 * @param bool             $isrewrite  是否启用urlrewrite
 * @param array            $object     把1.7里新增array型参数传给旧版本的接口
 *
 * @api Filter_Plugin_ViewPost_Begin
 * @api Filter_Plugin_ViewPost_Begin_V2
 * @api Filter_Plugin_ViewPost_Template
 *
 * @throws Exception
 *
 * @return string
 */
function ViewPost($id = null, $alias = null, $isrewrite = false, $object = array())
{
    global $zbp;

    $fpargs_v1 = array();
    $fpargs_v2 = call_user_func('func_get_args');
    $fpargs = &$fpargs_v2;

    //新版本的函数V2 (v2版本传入的第一个参数是array且只传一个array)
    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewPost_Begin_V2'] as $fpname => &$fpsignal) {
        $fpreturn = call_user_func_array($fpname, $fpargs_v2);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    //修正首个参数使用array而不传入后续参数的情况
    if (is_array($id)) {
        $object = $id;
        $isrewrite = true;
        $posttype = GetValueInArray($object, 'posttype', 0);
        $canceldisplay = GetValueInArray($object, 'canceldisplay', false);
        $route = GetValueInArray($object, 'route', array());
        $post = GetValueInArray($object, 'post', null);
        $alias = GetValueInArray($object, 'alias', null);
        $id = GetValueInArray($object, 'id', null);
    } else {
        $post = null;
        $object = array();
        $posttype = null;
        $canceldisplay = false;
    }

    //处理上一版本兼容性的问题
    $fpargs_v1 = array($id, $alias, $isrewrite, $object);

    //兼容老版本的接口
    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewPost_Begin'] as $fpname => &$fpsignal) {
        $fpreturn = call_user_func_array($fpname, $fpargs_v1);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    //从$post中读取正确的$id或$alias
    if (isset($route['args']) && is_array($route['args'])) {
        $parameters = UrlRule::ProcessParameters($route);
        foreach ($parameters as $key => $value) {
            if ($value['name'] == 'id') {
                $id = $post;
                if (function_exists('ctype_digit') && !ctype_digit((string) $id)) {
                    $id = null;
                }
            }
            if ($value['name'] == 'alias') {
                $alias = $post;
            }
        }
    }

    $select = '';
    $w = array();
    $order = null;
    $limit = 1;
    $option = null;

    $w[] = array('=', 'log_Type', $posttype);

    if ($id !== null && is_numeric($id)) {
        if (function_exists('ctype_digit') && !ctype_digit((string) $id)) {
            $zbp->ShowError(3, __FILE__, __LINE__);
        }

        $w[] = array('=', 'log_ID', $id);
    } elseif ($alias !== null) {
        if ($zbp->option['ZC_POST_ALIAS_USE_ID_NOT_TITLE'] == false) {
            $w[] = array('array', array(array('log_Alias', $alias), array('log_Title', $alias)));
        } else {
            $w[] = array('array', array(array('log_Alias', $alias), array('log_ID', $alias)));
        }
    } else {
        $zbp->ShowError(2, __FILE__, __LINE__);
        exit;
    }

    if (empty($zbp->user->ID)) {
        $w[] = array('=', 'log_Status', 0);
    }

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewPost_Core'] as $fpname => &$fpsignal) {
        $fpname($select, $w, $order, $limit, $option);
    }

    $articles = $zbp->GetPostList($select, $w, $order, $limit, $option);
    if (count($articles) == 0) {
        if (!empty($route) || $isrewrite == true) {
            return false;
        }
        $zbp->ShowError(2, __FILE__, __LINE__);
    }

    $article = $articles[0];

    if ($posttype != $article->Type) {
        return false;
    } else {
        $classname = $zbp->GetPostType($posttype, 'classname');
        if (strcasecmp(get_class($article), $classname) != 0) {
            $newarticle = $article->Cloned($classname);
            $article = $newarticle;
            $zbp->AddPostCache($article);
        }
    }

    if ($article->Status != 0 && !$zbp->CheckRights($article->TypeActions['all']) && ($article->AuthorID != $zbp->user->ID)) {
        $zbp->ShowError(2, __FILE__, __LINE__);
    }

    if (!empty($route) || $isrewrite == true) {
        if (isset($object[0]) && !isset($object['page'])) {
            if (!(stripos(urldecode($article->Url), $object[0]) !== false)) {
                $zbp->ShowError(2, __FILE__, __LINE__);
            }
        }
    }

    $zbp->LoadTagsByIDString($article->Tag);

    if (isset($zbp->option['ZC_VIEWNUMS_TURNOFF']) && $zbp->option['ZC_VIEWNUMS_TURNOFF'] == false) {
        $article->ViewNums += 1;
        $sql = $zbp->db->sql->Update($zbp->table['Post'], array('log_ViewNums' => $article->ViewNums), array(array('=', 'log_ID', $article->ID)));
        $zbp->db->Update($sql);
    }

    $pagebar = new Pagebar('javascript:zbp.comment.get(\'' . $article->ID . '\',\'{%page%}\');', false);
    $pagebar->PageCount = $zbp->commentdisplaycount;
    $pagebar->PageNow = 1;
    $pagebar->PageBarCount = $zbp->pagebarcount;
    $pagebar->UrlRule->RulesObject = &$article;

    if ($zbp->option['ZC_COMMENT_TURNOFF']) {
        $article->IsLock = true;
    }

    $comments = array();

    if (!$article->IsLock && $zbp->socialcomment == null) {
        $comments = $zbp->GetCommentList(
            '*',
            array(
                array('=', 'comm_LogID', $article->ID),
                array('=', 'comm_RootID', 0),
                array('=', 'comm_IsChecking', 0),
            ),
            array('comm_ID' => ($zbp->option['ZC_COMMENT_REVERSE_ORDER'] ? 'DESC' : 'ASC')),
            array(($pagebar->PageNow - 1) * $pagebar->PageCount, $pagebar->PageCount),
            array('pagebar' => $pagebar)
        );
        $rootid = array();
        foreach ($comments as &$comment) {
            $rootid[] = $comment->ID;
        }
        $comments2 = $zbp->GetCommentList(
            '*',
            array(
                array('=', 'comm_LogID', $article->ID),
                array('IN', 'comm_RootID', $rootid),
                array('=', 'comm_IsChecking', 0),
            ),
            array('comm_ID' => ($zbp->option['ZC_COMMENT_REVERSE_ORDER'] ? 'DESC' : 'ASC')),
            null,
            null
        );
        $floorid = (($pagebar->PageNow - 1) * $pagebar->PageCount);
        foreach ($comments as &$comment) {
            $floorid += 1;
            $comment->FloorID = $floorid;
            $comment->Content = FormatString($comment->Content, '[enter]');
            if (strpos($zbp->template->templates['comment'], 'id="AjaxComment') === false) {
                $comment->Content .= '<label id="AjaxComment' . $comment->ID . '"></label>';
            }
        }
        foreach ($comments2 as &$comment) {
            $comment->Content = FormatString($comment->Content, '[enter]');
            if (strpos($zbp->template->templates['comment'], 'id="AjaxComment') === false) {
                $comment->Content .= '<label id="AjaxComment' . $comment->ID . '"></label>';
            }
        }
    }

    $zbp->LoadMembersInList($comments);

    $zbp->template->SetTags('posttype', $article->Type);
    $zbp->template->SetTags('title', ($article->Status == 0 ? '' : '[' . $zbp->lang['post_status_name'][$article->Status] . ']') . $article->Title);
    $zbp->template->SetTags('url', $article->Url);
    $zbp->template->SetTags('article', $article);
    $zbp->template->SetTags('type', $article->TypeName);
    $zbp->template->SetTags('page', 1);

    if ($pagebar->PageAll == 0 || $pagebar->PageAll == 1) {
        $pagebar = null;
    }
    $zbp->template->SetTags('pagebar', $pagebar);
    $zbp->template->SetTags('commentspagebar', $pagebar);
    $zbp->template->SetTags('commentspage', 1);
    $zbp->template->SetTags('comments', $comments);

    $zbp->template->SetTags('args', $fpargs);
    $zbp->template->SetTags('route', $route);

    if ($zbp->template->hasTemplate($article->Template)) {
        $zbp->template->SetTemplate($article->Template);
    } else {
        $zbp->template->SetTemplate($zbp->option['ZC_POST_DEFAULT_TEMPLATE']);
    }

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewPost_Template'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname($zbp->template);
        if ($fpsignal == PLUGIN_EXITSIGNAL_RETURN) {
            $fpsignal = PLUGIN_EXITSIGNAL_NONE;

            return $fpreturn;
        }
    }

    if ($canceldisplay == false) {
        $zbp->template->Display();
    }

    return true;
}

/**
 * 显示文章下评论列表.
 *
 * @param int $postid 文章ID
 * @param int $page   页数
 *
 * @throws Exception
 *
 * @return bool
 */
function ViewComments($postid, $page)
{
    global $zbp;

    $post = new Post();
    $post = $zbp->GetPostByID($postid);
    $page = $page == 0 ? 1 : $page;
    $template = 'comments';

    $pagebar = new Pagebar('javascript:zbp.comment.get(\'' . $post->ID . '\',\'{%page%}\');');
    $pagebar->PageCount = $zbp->commentdisplaycount;
    $pagebar->PageNow = $page;
    $pagebar->PageBarCount = $zbp->pagebarcount;
    //$pagebar->Count = $post->CommNums;

    $comments = array();

    $comments = $zbp->GetCommentList(
        '*',
        array(
            array('=', 'comm_LogID', $post->ID),
            array('=', 'comm_RootID', 0),
            array('=', 'comm_IsChecking', 0),
        ),
        array('comm_ID' => ($zbp->option['ZC_COMMENT_REVERSE_ORDER'] ? 'DESC' : 'ASC')),
        array(($pagebar->PageNow - 1) * $pagebar->PageCount, $pagebar->PageCount),
        array('pagebar' => $pagebar)
    );
    $rootid = array();
    foreach ($comments as $comment) {
        $rootid[] = array('comm_RootID', $comment->ID);
    }
    $comments2 = $zbp->GetCommentList(
        '*',
        array(
            array('=', 'comm_LogID', $post->ID),
            array('array', $rootid),
            array('=', 'comm_IsChecking', 0),
        ),
        array('comm_ID' => ($zbp->option['ZC_COMMENT_REVERSE_ORDER'] ? 'DESC' : 'ASC')),
        null,
        null
    );

    $floorid = (($pagebar->PageNow - 1) * $pagebar->PageCount);
    foreach ($comments as &$comment) {
        $floorid += 1;
        $comment->FloorID = $floorid;
        $comment->Content = FormatString($comment->Content, '[enter]');
        if (strpos($zbp->template->templates['comment'], 'id="AjaxComment') === false) {
            $comment->Content .= '<label id="AjaxComment' . $comment->ID . '"></label>';
        }
    }
    foreach ($comments2 as &$comment) {
        $comment->Content = FormatString($comment->Content, '[enter]');
        if (strpos($zbp->template->templates['comment'], 'id="AjaxComment') === false) {
            $comment->Content .= '<label id="AjaxComment' . $comment->ID . '"></label>';
        }
    }

    $zbp->template->SetTags('title', $zbp->title);
    $zbp->template->SetTags('article', $post);
    $zbp->template->SetTags('type', 'comment');

    if ($pagebar->PageAll == 1) {
        $pagebar = null;
    }

    $zbp->template->SetTags('pagebar', $pagebar);
    $zbp->template->SetTags('commentspagebar', $pagebar);
    $zbp->template->SetTags('commentspage', $page);
    $zbp->template->SetTags('comments', $comments);

    $zbp->template->SetTemplate($template);

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewComments_Template'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname($zbp->template);
    }

    $s = $zbp->template->Output();

    $a = explode('<label id="AjaxCommentBegin"></label>', $s);
    $s = $a[1];
    $a = explode('<label id="AjaxCommentEnd"></label>', $s);
    $s = $a[0];

    echo $s;

    return true;
}

/**
 * 显示评论.
 *
 * @param int $id 评论ID
 *
 * @throws Exception
 *
 * @return bool
 */
function ViewComment($id)
{
    global $zbp;

    $template = 'comment';
    /* @var Comment $comment */
    $comment = $zbp->GetCommentByID($id);
    $post = new Post();
    $post = $zbp->GetPostByID($comment->LogID);

    $comment->Content = FormatString(htmlspecialchars($comment->Content), '[enter]');
    if (strpos($zbp->template->templates['comment'], 'id="AjaxComment') === false) {
        $comment->Content .= '<label id="AjaxComment' . $comment->ID . '"></label>';
    }

    $zbp->template->SetTags('title', $zbp->title);
    $zbp->template->SetTags('comment', $comment);
    $zbp->template->SetTags('article', $post);
    $zbp->template->SetTags('type', 'comment');
    $zbp->template->SetTags('page', 1);
    $zbp->template->SetTemplate($template);

    foreach ($GLOBALS['hooks']['Filter_Plugin_ViewComment_Template'] as $fpname => &$fpsignal) {
        $fpreturn = $fpname($zbp->template);
    }

    $zbp->template->Display();

    return true;
}
