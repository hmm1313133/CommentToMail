<?php
/**
 * 评论邮件提醒插件,同时包括监控和异步方法
 *
 * @package CommentToMail
 * @author Hmm
 * @version 2.2.0
 * @link https://www.temdu.com
 * 
 *
 * 基于Uniartisan维护版本添加异步发送方法同时感谢Byends（https://github.com/byends/CommentToMail/）和DEFE两位作者
 *
 */
class CommentToMail_Plugin implements Typecho_Plugin_Interface
{
    /** @var string 提交路由前缀 */
    public static $action = 'comment-to-mail';

    /** @var bool 内部请求User-Agent */
    public static $ua = 'MailMessageBrid';

    /** @var string 控制菜单链接 */
    public static $panel  = 'CommentToMail/page/console.php';

    /** @var bool 是否记录日志 */
    private static $_isMailLog  = false;
    
    /** @var bool 请求适配器 */
    private static $_adapter    = false;

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        if (false == self::isAvailable()) {
            throw new Typecho_Plugin_Exception(_t('对不起, 您的主机没有打开 allow_url_fopen 功能而且不支持 php-curl 扩展, 无法正常使用此功能'));
        }

        if (false == self::isWritable(dirname(__FILE__) . '/cache/')) {
            throw new Typecho_Plugin_Exception(_t('对不起，插件目录不可写，无法正常使用此功能'));
        }

		self::dbInstall();
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array('CommentToMail_Plugin', 'parseComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = array('CommentToMail_Plugin', 'parseComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark = array('CommentToMail_Plugin', 'eventApproved');

        // 检测当前版本
        if ( version_compare(str_replace('/', '.', Typecho_Common::VERSION), '1.1.17.10.30') ) {
            Typecho_Plugin::factory('Widget_Service')->asyncRequest = array('CommentToMail_Plugin', 'asyncRequest');
        }

        Helper::addAction(self::$action, 'CommentToMail_Action');
        Helper::addRoute('commentToMailProcessQueue', '/commentToMailProcessQueue/', 'CommentToMail_Action', 'processQueue');
        Helper::addPanel(1, self::$panel, '评论邮件提醒', '评论邮件提醒控制台', 'administrator');
        return _t('请设置邮箱信息，以使插件正常使用！');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        Helper::removeAction(self::$action);
        Helper::removeRoute('commentToMailProcessQueue');
        Helper::removePanel(1, self::$panel);
        self::dbUninstall();
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $options = Typecho_Widget::widget('Widget_Options');
           echo "<a href='https://www.temdu.com/2018/04/77.html'>请在设置前仔细阅读相关说明</a>";
        $mode= new Typecho_Widget_Helper_Form_Element_Radio('mode',
                array( 'smtp' => 'smtp',
                       'mail' => 'mail()',
                       'sendmail' => 'sendmail()'),
                'smtp', '发信方式');
        $form->addInput($mode);

        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, 'smtp.',
                _t('SMTP地址'), _t('请填写 SMTP 服务器地址'));
        $form->addInput($host->addRule('required', _t('必须填写一个SMTP服务器地址')));

        $port = new Typecho_Widget_Helper_Form_Element_Text('port', NULL, '25',
                _t('SMTP端口'), _t('SMTP服务端口,一般为25。'));
        $port->input->setAttribute('class', 'mini');
        $form->addInput($port->addRule('required', _t('必须填写SMTP服务端口'))
                ->addRule('isInteger', _t('端口号必须是纯数字')));

        $user = new Typecho_Widget_Helper_Form_Element_Text('user', NULL, NULL,
                _t('SMTP用户'),_t('SMTP服务验证用户名,一般为邮箱名如：youname@domain.com'));
        $form->addInput($user->addRule('required', _t('SMTP服务验证用户名')));

        $pass = new Typecho_Widget_Helper_Form_Element_Password('pass', NULL, NULL,
                _t('SMTP密码'));
        $form->addInput($pass->addRule('required', _t('SMTP服务验证密码')));

        $validate = new Typecho_Widget_Helper_Form_Element_Checkbox('validate',
                array('validate'=>'服务器需要验证',
                    'ssl'=>'ssl加密'),
                array('validate'),'SMTP验证');
        $form->addInput($validate);
        
        $fromName = new Typecho_Widget_Helper_Form_Element_Text('fromName', NULL, NULL,
                _t('发件人名称'),_t('发件人名称，留空则使用博客标题'));
        $form->addInput($fromName);

        $mail = new Typecho_Widget_Helper_Form_Element_Text('mail', NULL, NULL,
                _t('接收邮件的地址'),_t('接收邮件的地址,如为空则使用文章作者个人设置中的邮件地址！'));
        $form->addInput($mail->addRule('email', _t('请填写正确的邮件地址！')));

        $contactme = new Typecho_Widget_Helper_Form_Element_Text('contactme', NULL, NULL,
                _t('模板中“联系我”的邮件地址'),_t('联系我用的邮件地址,如为空则使用文章作者个人设置中的邮件地址！'));
        $form->addInput($contactme->addRule('email', _t('请填写正确的邮件地址！')));

        $status = new Typecho_Widget_Helper_Form_Element_Checkbox('status',
                array('approved' => '提醒已通过评论',
                        'waiting' => '提醒待审核评论',
                        'adopt' => '提醒评论审核通过',
                        'spam' => '提醒垃圾评论'),
                array('approved', 'waiting'), '提醒设置',_t('该选项仅针对博主，访客只发送已通过的评论。'));
        $form->addInput($status);

        $other = new Typecho_Widget_Helper_Form_Element_Checkbox('other',
                array('to_owner' => '有评论及回复时，发邮件通知博主。',
                    'to_guest' => '评论被回复时，发邮件通知评论者。',
                    'to_me'=>'自己回复自己的评论时，发邮件通知。(同时针对博主和访客)',
                    'to_log' => '记录邮件发送日志。'),
                array('to_owner','to_guest'), '其他设置',_t('选中该选项插件会在数据库log中记录发送日志。'));
        $form->addInput($other->multiMode());

        $titleForOwner = new Typecho_Widget_Helper_Form_Element_Text('titleForOwner',null,"[{title}] 一文有新的评论",
                _t('博主接收邮件标题'));
        $form->addInput($titleForOwner->addRule('required', _t('博主接收邮件标题 不能为空')));

        $titleForGuest = new Typecho_Widget_Helper_Form_Element_Text('titleForGuest',null,"您在 [{title}] 的评论有了回复",
                _t('访客接收邮件标题'));
        $form->addInput($titleForGuest->addRule('required', _t('访客接收邮件标题 不能为空')));

        $sendmode= new Typecho_Widget_Helper_Form_Element_Radio('sendmode',
            array( 'asy' => '异步发送',
                'corn' => '计划任务'),
            'asy', '调用方式');
        $form->addInput($sendmode);
        
        $entryUrl = ($options->rewrite) ? $options->siteUrl : $options->siteUrl . 'index.php';

        $deliverMailUrl = rtrim($entryUrl, '/') . '/action/' . self::$action . '?do=deliverMail&key=[yourKey]';
        $key = new Typecho_Widget_Helper_Form_Element_Text('key',null, Typecho_Common::randString(16),
                _t('key'), _t('执行发送任务地址为（ 请注意：实际地址不包括[ ] ）'.$deliverMailUrl) );
        $form->addInput($key->addRule('required', _t('key 不能为空.')));

        $nonAuthUrl = rtrim($entryUrl, '/') . '/commentToMailProcessQueue/';
        $nonAuth = new Typecho_Widget_Helper_Form_Element_Checkbox('verify',
                array('nonAuth'=>'开启不验证key（仅特殊环境下及调试时使用使用，建议无需求不要勾选，以防被用于恶意消耗服务器资源) '.$nonAuthUrl),
                array(),'执行验证');
        $form->addInput($nonAuth);

        $clean_time = new Typecho_Widget_Helper_Form_Element_Select('clean_time',
                array('no_clean' => '不清理',
                    'immediate' => '发送成功后立即清理'
					),
                'no_clean', _t('清理时间'), _t('已发送邮件数据移除的时间'));
        $form->addInput($clean_time);
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {}

	public static function dbInstall()
	{
		$installDb = Typecho_Db::get();
		$type = explode('_', $installDb->getAdapterName());
		$type = array_pop($type);
		$prefix = $installDb->getPrefix();
		$scripts = file_get_contents('usr/plugins/CommentToMail/'.$type.'.sql');
		$scripts = str_replace('typecho_', $prefix, $scripts);
		$scripts = str_replace('%charset%', 'utf8', $scripts);
		$scripts = explode(';', $scripts);
		try {
			foreach ($scripts as $script) {
				$script = trim($script);
				if ($script) {
					$installDb->query($script, Typecho_Db::WRITE);
				}
			}
			return '建立邮件队列数据表，插件启用成功';
		} catch (Typecho_Db_Exception $e) {
			$code = $e->getCode();
			if(('Mysql' == $type && 1050 == $code) ||
					('SQLite' == $type && ('HY000' == $code || 1 == $code))) {
				try {
					$script = 'SELECT `id`, `content`, `sent` FROM `' . $prefix . 'mail`';
					$installDb->query($script, Typecho_Db::READ);
					return '检测到邮件队列数据表，插件启用成功';					
				} catch (Typecho_Db_Exception $e) {
					$code = $e->getCode();
					if(('Mysql' == $type && 1054 == $code) ||
							('SQLite' == $type && ('HY000' == $code || 1 == $code))) {
						return Links_Plugin::linksUpdate($installDb, $type, $prefix);
					}
					throw new Typecho_Plugin_Exception('数据表检测失败，插件启用失败。错误号：'.$code);
				}
			} else {
				throw new Typecho_Plugin_Exception('数据表建立失败，插件启用失败。错误号：'.$code);
			}
		}
	}

    /**
     * 插件禁用删除数据表
     *
     * @access public
     * @param $comment 调用参数
     * @return void
     */
	public static function dbUninstall()
    {
        $uninstallDb = Typecho_Db::get();
        $prefix = $uninstallDb->getPrefix();
        $uninstallDb->query('drop table '.$prefix.'mail');
    }

    /**
     * 通过审核邮件内容
     *
     * @access public
     * @param $comment 调用参数
     * @return void
     */
    public static function eventApproved($comment, $content, $status){
        self::saveLog(date('Y-m-d H:i:s', time())."测试\r\n");
        if ( 'approved' == $status ) {
            $content->status='approved';
            self::parseComment($content);
        }
    }


    /**
     * 获取邮件内容
     *
     * @access public
     * @param $comment 调用参数
     * @return void
     */
    public static function parseComment($comment)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $cfg = array(
            'siteTitle' => $options->title,
            'timezone'  => $options->timezone,
            'cid'       => $comment->cid,
            'coid'      => $comment->coid,
            'created'   => $comment->created,
            'author'    => $comment->author,
            'authorId'  => $comment->authorId,
            'ownerId'   => $comment->ownerId,
            'mail'      => $comment->mail,
            'ip'        => $comment->ip,
            'title'     => $comment->title,
            'text'      => $comment->text,
            'permalink' => $comment->permalink,
            'status'    => $comment->status,
            'parent'    => $comment->parent,
            'manage'    => $options->siteUrl . __TYPECHO_ADMIN_DIR__ . "manage-comments.php"
        );

        self::$_isMailLog = in_array('to_log', Helper::options()->plugin('CommentToMail')->other) ? true : false;

        // 添加至队列
        self::saveLog("邮件添加至队列\r\n");
        $cfg      = (object)$cfg;
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $id = $db->query(
            $db->insert($prefix.'mail')->rows(array(
                'content' => base64_encode(serialize($cfg)),
                'sent' => 0
            ))
        );

        $_cfg = Helper::options()->plugin('CommentToMail');
        self::saveLog($comment->parent);
        if($_cfg->sendmode=='asy'){
            self::saveLog("开始执行异步请求CS\r\n");
            //http://jb.temdu.com/index.php/action/comment-to-mail?do=deliverMail&DzaDLpImgj1M07B6
            $url = ($options->rewrite) ? $options->siteUrl : $options->siteUrl . 'index.php';
            $url = rtrim($url, '/') .  '/action/' . self::$action . '?do=deliverMail&key=' .$_cfg->key;
            if ( version_compare(str_replace('/', '.', Typecho_Common::VERSION), '1.1.17.10.30') ) {
                // 调用异步回调模式
                Helper::requestService('asyncRequest', $url);
            }else{
                self::asyncRequest($url);
            }
        }
    }

    /**
     * 发送异步请求
     * @param $url
     */
    public static function asyncRequest($url)
    {
        self::isAvailable();
        self::$_adapter == 'Socket' ? self::socket($url) : self::curl($url);
    }

    /**
     * Socket 请求
     * @param $url
     * @return bool
     */
    public static function socket($url)
    {
        $params = parse_url($url);
        $path = $params['path'] . '?' . $params['query'];
        $host = $params['host'];
        $port = 80;
        $scheme = '';

        if ('https' == $params['scheme']) {
            $port = 443;
            $scheme = 'ssl://';
        }

        if (function_exists('fsockopen')) {
            $fp = @fsockopen ($scheme . $host, $port, $errno, $errstr, 30);
        } elseif (function_exists('pfsockopen')) {
            $fp = @pfsockopen ($scheme . $host, $port, $errno, $errstr, 30);
        } else {
            $fp = stream_socket_client($scheme . $host . ":$port", $errno, $errstr, 30);
        }

        if ($fp === false) {
            self::saveLog("SOCKET错误," . $errno . ':' . $errstr);
            self::curl($url);
            return false;
        }

        $out = "GET " . $path . " HTTP/1.1\r\n";
        $out .= "Host: $host\r\n";
        $out .= "Connection: Close\r\n\r\n";

        self::saveLog("Socket 方式发送\r\n");
        self::saveLog("请求链接：$url\r\n");
        fwrite($fp, $out);
        sleep(1);
        fclose($fp);
        self::saveLog("请求结束\r\n");
    }

    /**
     * Curl 请求
     * @param $url
     */
    public static function curl($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);  // 将curl_exec()获取的信息以文件流的形式返回,不直接输出。
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);  // 连接等待时间
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);         // curl允许执行时间
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // 跳过证书检查
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, TRUE);  // 从证书中检查SSL加密算法是否存在
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/536.11 (KHTML, like Gecko) Chrome/20.0.1132.47 Safari/536.11"); //设置浏览器标识


        self::saveLog("Curl 方式发送\r\n");

        self::saveLog("Curl:".curl_exec($ch)."\r\n");

        if($errno = curl_errno($ch)) {
            $error_message = curl_strerror($errno);
            //echo "cURL error ({$errno}):\n {$error_message}";
            self::saveLog("cURL error ({$errno}): {$error_message}\n");
        }
        //self::saveLog("执行发送请求：{$url}\n");
        curl_close($ch);
        self::saveLog("请求结束\r\n");
    }

    /**
     * 检测 适配器
     * @return string
     */
    public static function isAvailable()
    {
        function_exists('ini_get') && ini_get('allow_url_fopen') && (self::$_adapter = 'Socket');
        false == self::$_adapter && function_exists('curl_version') && (self::$_adapter = 'Curl');

        return self::$_adapter;
    }

    /**
     * 检测 是否可写
     * @param $file
     * @return bool
     */
    public static function isWritable($file)
    {
        if (is_dir($file)) {
            $dir = $file;
            if ($fp = @fopen("$dir/check_writable", 'w')) {
                @fclose($fp);
                @unlink("$dir/check_writable");
                $writeable = true;
            } else {
                $writeable = false;
            }
        } else {
            if ($fp = @fopen($file, 'a+')) {
                @fclose($fp);
                $writeable = true;
            } else {
                $writeable = false;
            }
        }

        return $writeable;
    }


    /**
     * 写入记录
     * @param $content
     * @return bool
     */
    public static function saveLog($content)
    {
        //if (!self::$_isMailLog) {
         //   return false;
        //}

        file_put_contents(dirname(__FILE__) . '/log/mailer_log.txt', $content, FILE_APPEND);
    }
}
