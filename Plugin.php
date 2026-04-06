<?php
/**
 * <strong>Bark推送评论通知</strong>
 * 
 * @package Comment2Bark
 * @author <strong>蛋蛋之家</strong>
 * @version 1.3.0
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
        // 检查 TYPECHO_VERSION 常量是否定义或版本是否小于 1.3.0
        // 这样可以兼容不同版本的 Typecho
        if (!defined('TYPECHO_VERSION') || version_compare(TYPECHO_VERSION, '1.3.0', '<')) {
            // 注册旧版本钩子，用于处理评论、trackback 和 pingback
            Typecho_Plugin::factory('Widget_Feedback')->comment = [self::class, 'barkSend'];
            Typecho_Plugin::factory('Widget_Feedback')->trackback = [self::class, 'barkSend'];
            Typecho_Plugin::factory('Widget_XmlRpc')->pingback = [self::class, 'barkSend'];
        } elseif (version_compare(TYPECHO_VERSION, '1.3.0', '>=')) {
            // 注册Typecho 1.3.0+的finishComment回调，这是新版本的推荐方式
            Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = [self::class, 'finishComment'];
        }
        
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
            // 获取插件配置
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
        }
        
        // 返回评论数据，确保不影响评论流程
        return $comment;
    }
    
    /**
     * 评论完成后回调（Typecho 1.3.0+）
     * 
     * @access public
     * @param object $comment 评论对象
     * @return object
     */
    public static function finishComment($comment) {
        $options = null;
        try {
            // 获取插件配置
            $options = self::getOptions();
            
            // 检查是否忽略自己的评论
            if (self::shouldIgnoreComment($comment, $options)) {
                return $comment;
            }
            
            // 准备推送数据，对于评论对象，不需要传递post参数
            $pushData = self::preparePushData($comment, null, $options);
            
            // 发送推送
            self::sendPush($pushData, $options);
        } catch (Exception $e) {
            // 使用Typecho的日志系统记录错误
            Typecho_Plugin::error(_t('Comment2Bark 推送失败: %s', $e->getMessage()));
        }
        
        // 返回评论对象，确保不影响评论流程
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
        
        // 处理评论对象（Typecho 1.3.0+）和评论数组
        $authorId = 0;
        if (is_object($comment) && isset($comment->authorId)) {
            $authorId = (int) $comment->authorId;
        } elseif (is_array($comment) && isset($comment['authorId'])) {
            $authorId = (int) $comment['authorId'];
        }
        
        $adminUid = !empty($options->adminUid) ? (int) $options->adminUid : 1;
        
        self::log("评论者ID: {$authorId}, 博主ID: {$adminUid}", $options);
        
        return $authorId === $adminUid;
    }
    
    /**
     * 准备推送数据
     * 
     * @param mixed $comment 评论结构（数组或对象）
     * @param object|null $post 被评论的文章（可选）
     * @param object $options 插件配置
     * @return array
     */
    private static function preparePushData($comment, $post = null, $options) {
        // 参数验证
        if (!is_array($comment) && !is_object($comment)) {
            throw new Exception('评论数据格式错误');
        }
        
        if (!is_object($options)) {
            throw new Exception('插件配置错误');
        }
        
        // 对于数组类型的评论，需要确保 post 参数存在
        if (is_array($comment) && !is_object($post)) {
            throw new Exception('文章数据缺失');
        }
        
        // 获取站点名称
        $siteName = Typecho_Widget::widget('Widget_Options')->title;
        $title = "站点「" . $siteName . "」有新评论";
        
        // 处理评论数据，根据类型获取相应字段
        $commentId = null;
        $author = '';
        $text = '';
        $postTitle = '';
        $commentUrl = '';
        
        if (is_object($comment)) {
            // 处理评论对象（Typecho 1.3.0+）
            $commentId = $comment->coid;
            $author = $comment->author;
            $text = $comment->text;
            $postTitle = $comment->title;
            
            /**
             * 关键修复：手动构建评论永久链接
             * 直接访问 $comment->permalink 在某些 Hook 中会因为上下文丢失只返回文章链接
             */
            $db = Typecho_Db::get();
            // 强制获取该评论对应的文章数据，确保路由解析有据可依
            $postData = $db->fetchRow($db->select()->from('table.contents')
                ->where('cid = ?', $comment->cid)
                ->limit(1));
            
            if ($postData) {
                // 绑定文章路由
                $postData = Typecho_Widget::widget('Widget_Abstract_Contents')->push($postData);
                // 计算评论分页（这是最重要的一步，防止只跳转到第一页）
                $commentPage = self::calculateCommentPage($commentId, $comment->cid);
                
                // 组合完整链接：文章链接 + 分页 + 锚点
                $commentUrl = $postData->permalink;
                if ($commentPage > 1) {
                    $separator = (strpos($commentUrl, '?') === false) ? '/' : '&';
                    $commentUrl .= $separator . 'comment-page-' . $commentPage;
                }
                $commentUrl .= '#comment-' . $commentId;
            } else {
                // 兜底方案：使用评论对象的 permalink 属性
                $commentUrl = $comment->permalink;
                // 确保链接包含评论锚点
                if (strpos($commentUrl, '#comment-') === false) {
                    $commentUrl .= '#comment-' . $commentId;
                }
            }
        } else {
            // 处理评论数组
            if (isset($comment['coid'])) {
                $commentId = $comment['coid'];
            } elseif (isset($comment['cid'])) {
                $commentId = $comment['cid'];
            }
            
            $author = $comment['author'];
            $text = $comment['text'];
            $postTitle = $post->title;
            
            // 计算评论分页
            $commentPage = 1;
            if ($commentId) {
                $commentPage = self::calculateCommentPage($commentId, $post->cid);
            }
            
            // 组合完整链接：文章链接 + 分页 + 锚点
            $commentUrl = $post->permalink;
            if ($commentPage > 1) {
                $separator = (strpos($commentUrl, '?') === false) ? '/' : '&';
                $commentUrl .= $separator . 'comment-page-' . $commentPage;
            }
            if ($commentId) {
                $commentUrl .= '#comment-' . $commentId;
            }
        }
        
        // 过滤评论中的HTML标签，处理连续换行符，然后截断
        // 首先使用 strip_tags 移除所有HTML标签
        $text = strip_tags($text);
        // 然后使用 htmlspecialchars 转义特殊字符，防止XSS攻击
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        // 处理连续换行符
        $text = preg_replace('/\n+/', ' ', $text);
        // 截断过长的文本
        $text = mb_strimwidth($text, 0, 150, "...");
        
        $body = $author . " 在「" . $postTitle . "」中说：\n" . $text;
        
        return [
            'title' => $title,
            'body'  => $body,
            'url'   => $commentUrl, // 确保传给 Bark 的 url 参数
            'icon'  => $options->barkIcon,
            'group' => $options->barkGroup,
            'isArchive' => $options->barkArchive,
            'sound' => $options->barkSound,
            'level' => 'active'
        ];
    }
    
    /**
     * 计算评论所在的页码
     * 
     * @param int $coid 评论ID
     * @param int $cid 文章ID
     * @return int 评论所在的页码
     */
    private static function calculateCommentPage($coid, $cid) {
        $options = Typecho_Widget::widget('Widget_Options');
        if (!$options->commentsPageBreak) return 1;

        $db = Typecho_Db::get();
        
        // 计算在该评论之前的评论数量
        $select = $db->select(array('COUNT(coid)' => 'num'))->from('table.comments')
            ->where('cid = ? AND status = ? AND coid <= ?', $cid, 'approved', $coid);
            
        if ($options->commentsOrder == 'DESC') {
            $select->where('parent = ?', 0);
        }
        
        try {
            $result = $db->fetchObject($select);
            $count = $result ? $result->num : 0;
            return ceil($count / $options->commentsPageSize);
        } catch (Exception $e) {
            // 发生异常时返回第一页
            return 1;
        }
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
        
        // 推送失败重试机制
        $maxRetries = 3;
        $retryDelay = 1; // 秒
        
        for ($retry = 0; $retry < $maxRetries; $retry++) {
            try {
                if (function_exists('curl_init')) {
                    $success = self::sendWithCurl($url, $data, $options);
                } else {
                    $success = self::sendWithFileGetContents($url, $data, $options);
                }
                
                if ($success) {
                    return;
                }
            } catch (Exception $e) {
                self::log('推送异常: ' . $e->getMessage(), $options);
            }
            
            // 重试前等待
            if ($retry < $maxRetries - 1) {
                self::log("推送失败，{$retryDelay}秒后重试...", $options);
                sleep($retryDelay);
                $retryDelay *= 2; // 指数退避
            }
        }
        
        self::log('推送失败，已达到最大重试次数', $options);
    }
    
    /**
     * 使用cURL发送推送
     * 
     * @param string $url
     * @param array $data
     * @param object $options
     * @return bool 是否推送成功
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
        $success = false;
        
        if (curl_errno($ch)) {
            self::log('cURL 错误: ' . curl_error($ch), $options);
        } else {
            self::log('cURL 响应: ' . $result, $options);
            // 检查响应是否成功
            $response = json_decode($result, true);
            if (isset($response['code']) && $response['code'] === 200) {
                $success = true;
            }
        }
        
        // 检查是否为有效资源，然后关闭
        if (is_resource($ch)) {
            curl_close($ch);
        }
        
        return $success;
    }
    
    /**
     * 使用file_get_contents发送推送
     * 
     * @param string $url
     * @param array $data
     * @param object $options
     * @return bool 是否推送成功
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
        
        // 检查响应是否成功
        $success = false;
        $response = json_decode($result, true);
        if (isset($response['code']) && $response['code'] === 200) {
            $success = true;
        }
        
        return $success;
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
