<?php
/**
 * Created by PhpStorm.
 * User: childeYin<尹少爷>
 * Date: 13/07/2018
 * Time: 6:32 PM
 */

use Google\Protobuf\Any;
use Zaly\Proto\Core\TransportData;
use Zaly\Proto\Core\TransportDataHeaderKey;
use Google\Protobuf\Internal\Message;

abstract class HttpBaseController extends \Wpf_Controller
{
    protected $userId;
    protected $userInfo;
    protected $sessionId;
    public $defaultErrorCode = "success";
    public $errorCode = "fail";
    protected $sessionIdTimeOut = 36000000; //10个小时的毫秒
    public $defaultFilePath = "files";
    public $whiteAction = [
        "page.login",
        "page.js",
        "page.siteConfig",
        "page.passport.login",
        "page.passport.account",
        "page.jump",
    ];
    private $groupType = "g";
    private $u2Type = "u";
    private $jumpRoomType = "";
    private $jumpRoomId = "";
    private $jumpRelation = "";
    private $siteCookieName = "zaly_site_user";

    public function __construct(Wpf_Ctx $context)
    {

        if(!$this->checkDBIsExist()) {
            $initUrl = ZalyConfig::getConfig("apiPageSiteInit");
            header("Location:" . $initUrl);
            exit();
        }

        $this->ctx = new BaseCtx();
    }


    /**
     * 处理方法， 根据bodyFormatType, 获取transData
     * @return string|void
     */
    public function doIndex()
    {
        $tag = __CLASS__ . "-" . __FUNCTION__;

        try {
            parent::doIndex();
            $preSessionId = isset($_GET['preSessionId']) ? $_GET['preSessionId'] : "";

            $x = isset($_GET['x']) ? $_GET['x'] : "";
            $action = isset($_GET['action']) ? $_GET['action'] : "";

            if ($preSessionId) {
                $this->handlePreSessionId();
                $apiPageIndex = ZalyConfig::getConfig("apiPageIndex");
                if($x) {
                    if (strpos($apiPageIndex, "?")) {
                        header("Location:" . $apiPageIndex."&x=".$x);
                    } else {
                        header("Location:" . $apiPageIndex."?x=".$x);
                    }
                } else {
                    header("Location:" . $apiPageIndex);
                }
                exit();
            }

            if(!in_array($action, $this->whiteAction)) {
                $this->getUserIdByCookie();
            }
            $this->getJumpUrl();
            $this->index();
        }catch (Exception $ex){
            $this->ctx->Wpf_Logger->error($tag, "error msg =" . $ex->getMessage());
            $this->setLogout();
        }
    }

    public function handlePreSessionId()
    {
        $preSessionId = isset($_GET['preSessionId']) ? $_GET['preSessionId'] : "";
        if ($preSessionId) {
            $preSessionId = isset($_GET['preSessionId']) ? $_GET['preSessionId'] : "";
            if($preSessionId) {
                $userProfile = $this->ctx->Site_Login->checkPreSessionIdFromPlatform($preSessionId);
                $this->setCookieBase64($userProfile["sessionId"], $this->siteCookieName);
            }
        }
    }

    public function getJumpUrl()
    {
        $tag = __CLASS__ . "-" . __FUNCTION__;

        try{
            $x = isset($_GET['x']) ? $_GET['x'] : "";
            if(!$x) {
                return ;
            }
            list($type, $id) = explode("-", $x);
            if($id == $this->userId) {
                return;
            }
            if($type == $this->groupType) {
                $this->jumpRoomType = "MessageRoomGroup";
                $isInGroupFlag = $this->ctx->SiteGroupTable->getGroupProfile($id, $this->userId);
                $this->jumpRelation = $isInGroupFlag != false ? 1 : 0;
            }elseif($type == $this->u2Type) {
                $this->jumpRoomType = "MessageRoomU2";
                $isFriendFlag = $this->ctx->SiteUserFriendTable->isFollow($this->userId, $id);
                $this->jumpRelation = $isFriendFlag > 0  ? 1 : 0;
            }
            $this->jumpRoomId = $id;
        }catch (Exception $ex) {
            $this->ctx->Wpf_Logger->error($tag, "error msg =" . $ex->getMessage());
        }
    }

    public function getUserIdByCookie()
    {
        $tag = __CLASS__ . "-" . __FUNCTION__;
        try {
            $this->checkUserCookie();
        } catch (Exception $ex) {
            $this->ctx->Wpf_Logger->error($tag, "error msg =" . $ex->getMessage());
            $this->setLogout();
        }
    }

    public function checkUserCookie()
    {
        $tag = __CLASS__ . "-" . __FUNCTION__;

        $this->sessionId   = isset($_COOKIE['zaly_site_user'] ) ?  $_COOKIE['zaly_site_user'] : "";
        if(!$this->sessionId) {
            throw new Exception("cookie is not found");
        }

        $this->sessionInfo = $this->ctx->SiteSessionTable->getSessionInfoBySessionId($this->sessionId);
        if (!$this->sessionInfo) {
            throw new Exception("session is not ok");
        }
        $timeActive = $this->sessionInfo['timeActive'];

        $nowTime = $this->ctx->ZalyHelper->getMsectime();

        if (($nowTime - $timeActive) > $this->sessionIdTimeOut) {
            throw new Exception("session is not ok");
        }

        $this->userInfo = $this->ctx->SiteUserTable->getUserByUserId($this->sessionInfo['userId']);
        if (!$this->userInfo) {
            throw new Exception("user is not ok");
        }

        $this->sessionId = $this->sessionInfo['sessionId'];
        $this->userId = $this->userInfo['userId'];

    }

    public function setLogout()
    {
        $x = isset($_GET['x']) ? $_GET['x'] : "";
        setcookie ($this->siteCookieName, "", time()-3600, "/", "", false, true);
        $apiPageLogin = ZalyConfig::getConfig("apiPageLogin");
        if($x) {
            if (strpos($apiPageLogin, "?")) {
                header("Location:" . $apiPageLogin."&x=".$x);
            } else {
                header("Location:" . $apiPageLogin."?x=".$x);
            }
        } else {
            header("Location:" . $apiPageLogin);
        }
        exit();
    }

    public function setTransDataHeaders($key, $val)
    {
        $key = "_{$key}";
        $this->headers[$key] = $val;
    }

    public function setHeader()
    {
        $this->setTransDataHeaders(TransportDataHeaderKey::HeaderSessionid, $this->sessionId);
        $this->setTransDataHeaders(TransportDataHeaderKey::HeaderUserAgent, $_SERVER['HTTP_USER_AGENT']);
    }

    public function display($viewName, $params = [])
    {
        // 自己实现实现一下这个方法，加载view目录下的文件
        $params['session_id'] = $this->sessionId;
        $params['user_id']   = $this->userId;
        $params['nickname']  = $this->userInfo['nickname'] ? $this->userInfo['nickname'] : "匿名";
        $params['loginName'] = ZalyHelper::hideMobile($this->userInfo['loginName']);
        $params['avatar']   = $this->userInfo['avatar'];
        $params['jumpPage'] = ZalyConfig::getApiPageJumpUrl();
        if(!isset($params['login'])) {
            $params['login'] = '';
        }
        $params['jumpRoomId'] = $this->jumpRoomId;
        $params['jumpRoomType'] = $this->jumpRoomType;
        $params['jumpRelation'] = $this->jumpRelation;

        return parent::display($viewName, $params);
    }

    /**
     * 查库操作
     */
    public function getSiteConfigFromDB($columns)
    {
        try {
            $results = $this->ctx->SiteConfigTable->selectSiteConfig($columns);
            return $results;
        } catch (Exception $e) {
            $tag = __CLASS__ . "-" . __FUNCTION__;
            $this->ctx->Wpf_Logger->error($tag, " errorMsg = " . $e->getMessage());
            return [];
        }
    }
}