<?php
/**
 * <strong>Bark推送评论通知</strong>
 * 
 * @package Comment2Bark
 * @author <strong>蛋蛋之家</strong>
 * @version 1.1.1
 * @link https://github.com/NoEggEgg/Comment2Bark
 */

class Comment2Bark_Plugin implements Typecho_Plugin_Interface {
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate() {
        Typecho_Plugin::factory('Widget_Feedback')->comment = [self::class, 'barkSend'];
        Typecho_Plugin::factory('Widget_Feedback')->trackback = [self::class, 'barkSend'];
        Typecho_Plugin::factory('Widget_XmlRpc')->pingback = [self::class, 'barkSend'];
        
        return _t('请配置此插件的 Bark Key, 以使您能顺利推送到Bark');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate() {}
    
    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config($form) {
        $server = new Typecho_Widget_Helper_Form_Element_Text('barkServer', null, 'https://api.day.app/', _t('服务器地址'), _t('（非必填）自定义 Bark 服务器地址，默认使用官方服务器'));
        $form->addInput($server);
        
        $key = new Typecho_Widget_Helper_Form_Element_Text('barkKey', null, null, _t('Bark Key'), _t('下载 Bark App 后获取的唯一密钥'));
        $key->addRule('required', _t('您必须填写一个正确的 Bark Key'));
        $form->addInput($key);
        
        $archive = new Typecho_Widget_Helper_Form_Element_Radio('barkArchive',
            [
                '1' => '保存',
                '0' => '不保存'
            ], '1', _t('消息保存'), _t('设置是否在 Bark App 中保存推送消息'));
        $form->addInput($archive);

        $ignoreSelf = new Typecho_Widget_Helper_Form_Element_Radio('ignoreSelf',
            [
                '1' => '是',
                '0' => '否'
            ], '0', _t('忽略自己的评论'), _t('启用后，若评论者为博主，则不会向 Bark 发送通知'));
        $form->addInput($ignoreSelf);
        
        $adminUid = new Typecho_Widget_Helper_Form_Element_Text('adminUid', null, '1', _t('博主 UID'), _t('（非必填）自定义博主 UID，默认为 1'));
        $form->addInput($adminUid);
        
        $icon = new Typecho_Widget_Helper_Form_Element_Text('barkIcon', null, null, _t('推送图标'), _t('（非必填）自定义 Bark 推送图标，图标需为完整的 URL 链接'));
        $form->addInput($icon);
        
        $group = new Typecho_Widget_Helper_Form_Element_Text('barkGroup', null, null, _t('消息分组'), _t('（非必填）自定义 Bark 消息分组，用于消息分类'));
        $form->addInput($group);
        
        $sound = new Typecho_Widget_Helper_Form_Element_Text('barkSound', null, null, _t('提示音'), _t('（非必填）自定义 Bark 消息提示音，参考 https://github.com/Finb/Bark/tree/master/Sounds'));
        $form->addInput($sound);
        
        $debug = new Typecho_Widget_Helper_Form_Element_Radio('debug',
            [
                '1' => '启用',
                '0' => '禁用'
            ], '0', _t('调试模式'), _t('启用后，会在日志中记录推送请求和响应信息'));
        $form->addInput($debug);
    }
    
    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig($form) {}

    /**
     * 推送到Bark
     * 
     * @access public
     * @param array $comment 评论结构
     * @param Typecho_Widget $post 被评论的文章
     * @return array
     */
    public static function barkSend($comment, $post) {
        $options = null;
        try {
            $options = self::getOptions();
            
            // 检查是否忽略自己的评论
            if (self::shouldIgnoreComment($comment, $options)) {
                return $comment;
            }
            
            // 准备推送数据
            $pushData = self::preparePushData($comment, $post, $options);
            
            // 发送推送
            self::sendPush($pushData, $options);
        } catch (Exception $e) {
            // 使用Typecho的日志系统记录错误
            Typecho_Plugin::error(_t('Comment2Bark 推送失败: %s', $e->getMessage()));
            
            // 同时使用error_log作为备份
            error_log('Comment2Bark: 推送失败: ' . $e->getMessage());
        }
        
        return $comment;
    }
    
    /**
     * 获取插件配置
     * 
     * @return Typecho_Config
     */
    private static function getOptions() {
        return Typecho_Widget::widget('Widget_Options')->plugin('Comment2Bark');
    }
    
    /**
     * 检查是否应该忽略评论
     * 
     * @param array $comment
     * @param object $options
     * @return bool
     */
    private static function shouldIgnoreComment($comment, $options) {
        if ($options->ignoreSelf != '1') {
            return false;
        }
        
        $authorId = isset($comment['authorId']) ? (int) $comment['authorId'] : 0;
        $adminUid = !empty($options->adminUid) ? (int) $options->adminUid : 1;
        
        self::log("评论者ID: {$authorId}, 博主ID: {$adminUid}", $options);
        
        return $authorId === $adminUid;
    }
    
    /**
     * 准备推送数据
     * 
     * @param array $comment
     * @param object $post
     * @param object $options
     * @return array
     */
    private static function preparePushData($comment, $post, $options) {
        $title = '你的博客有了新评论';
        
        // 构建指向评论位置的链接
        $commentLink = $post->permalink;
        if (isset($comment['coid'])) {
            $commentLink .= '#comment-' . $comment['coid'];
        }
        
        $body = $comment['author'] . " 在「" . $post->title . "」中说：\n\n" . $comment['text'] . "\n\n" . $commentLink;
        
        return [
            'title' => $title,
            'body' => $body,
            'icon' => $options->barkIcon,
            'group' => $options->barkGroup,
            'archive' => $options->barkArchive,
            'sound' => $options->barkSound
        ];
    }
    
    /**
     * 发送推送
     * 
     * @param array $data
     * @param object $options
     */
    private static function sendPush($data, $options) {
        $barkKey = $options->barkKey;
        $barkServer = $options->barkServer ?: 'https://api.day.app/';
        
        // 验证必要参数
        if (empty($barkKey)) {
            self::log('Bark Key 未配置', $options);
            return;
        }
        
        // 验证服务器地址
        if (!filter_var($barkServer, FILTER_VALIDATE_URL)) {
            self::log('服务器地址无效', $options);
            return;
        }
        
        // 过滤空参数
        $data = array_filter($data, fn($value) => $value !== null && $value !== '');
        
        $url = rtrim($barkServer, '/') . '/' . $barkKey;
        
        self::log("发送请求到: {$url}", $options);
        self::log("请求参数: " . http_build_query($data), $options);
        
        if (function_exists('curl_init')) {
            self::sendWithCurl($url, $data, $options);
        } else {
            self::sendWithFileGetContents($url, $data, $options);
        }
    }
    
    /**
     * 使用cURL发送推送
     * 
     * @param string $url
     * @param array $data
     * @param object $options
     */
    private static function sendWithCurl($url, $data, $options) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $result = curl_exec($ch);
        
        if (curl_errno($ch)) {
            self::log('cURL 错误: ' . curl_error($ch), $options);
        } else {
            self::log('cURL 响应: ' . $result, $options);
        }
        
        // 检查是否为有效资源，然后关闭
        if (is_resource($ch)) {
            curl_close($ch);
        }
    }
    
    /**
     * 使用file_get_contents发送推送
     * 
     * @param string $url
     * @param array $data
     * @param object $options
     */
    private static function sendWithFileGetContents($url, $data, $options) {
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query($data),
                'timeout' => 10
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];

        $context = stream_context_create($opts);
        $result = file_get_contents($url, false, $context);
        
        self::log('file_get_contents 响应: ' . $result, $options);
    }
    
    /**
     * 记录日志
     * 
     * @param string $message
     * @param object $options
     */
    private static function log($message, $options) {
        if ($options->debug == '1') {
            error_log('Comment2Bark: ' . $message);
        }
    }
}
