<?php
/**
 * Bark推送评论通知
 *
 * @package Comment2Bark
 * @author 蛋蛋之家
 * @version 1.4.0
 * @link https://github.com/NoEggEgg/Comment2Bark
 */

class Comment2Bark_Plugin implements Typecho_Plugin_Interface {
    /** @var int 最大重试次数 */
    private const MAX_RETRIES = 3;
    /** @var int 最大文本长度 */
    private const MAX_TEXT_LENGTH = 150;
    /** @var int 默认博主UID */
    private const DEFAULT_ADMIN_UID = 1;
    /** @var string 默认服务器 */
    private const DEFAULT_SERVER = 'https://api.day.app/';
    /** @var string 评论状态 */
    private const STATUS_APPROVED = 'approved';

    /**
     * 激活插件
     */
    public static function activate() {
        // 同时注册前台提交和后台编辑的 finishComment 钩子
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = [self::class, 'finishComment'];
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = [self::class, 'finishComment'];
        
        return _t('请配置 Bark Key 以启用推送');
    }

    public static function deactivate() {}

    /**
     * 配置面板
     */
    public static function config($form) {
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'barkServer', null, self::DEFAULT_SERVER, _t('服务器地址'), _t('默认使用官方服务器')));
        
        $key = new Typecho_Widget_Helper_Form_Element_Text('barkKey', null, null, _t('Bark Key'), _t('必填，从 Bark App 获取'));
        $key->addRule('required', _t('请填写 Bark Key'));
        $form->addInput($key);
        
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('barkArchive', [1 => '保存', 0 => '不保存'], 1, _t('消息保存')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('ignoreSelf', [1 => '是', 0 => '否'], 0, _t('忽略自己')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('adminUid', null, '1', _t('博主 UID')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('barkIcon', null, null, _t('推送图标')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('barkGroup', null, null, _t('消息分组')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('barkSound', null, null, _t('提示音')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('debug', [1 => '启用', 0 => '禁用'], 0, _t('调试模式')));
    }

    public static function personalConfig($form) {}

    /**
     * 评论处理入口（统一方法）
     * finishComment 传递评论对象，只有一个参数
     */
    public static function finishComment($comment) {
        $options = self::getOptions();
        
        // 增强调试日志 - 输出完整的 comment 结构和类型
        if ($options->debug == '1') {
            $type = gettype($comment);
            $debugInfo = "finishComment 调用:\n";
            $debugInfo .= "- 类型: {$type}\n";
            
            if ($type === 'object') {
                $debugInfo .= "- 对象类: " . get_class($comment) . "\n";
                $debugInfo .= "- 可见属性:\n";
                foreach (get_object_vars($comment) as $key => $value) {
                    $debugInfo .= "  {$key} = " . (is_scalar($value) ? $value : gettype($value)) . "\n";
                }
            } elseif ($type === 'array') {
                $debugInfo .= "- 数组内容:\n";
                foreach ($comment as $key => $value) {
                    $debugInfo .= "  {$key} = " . (is_scalar($value) ? $value : gettype($value)) . "\n";
                }
            }
            
            self::log($debugInfo, $options);
        }
        
        try {
            if (self::shouldIgnore($comment, $options)) {
                return $comment;
            }
            
            self::sendPush(self::preparePushData($comment, null, $options), $options);
        } catch (Exception $e) {
            Typecho_Plugin::error(_t('Comment2Bark 推送失败: %s', $e->getMessage()));
        }
        
        return $comment;
    }
    
    /**
     * 旧版 Typecho 评论处理方法
     */
    public static function handleComment($comment, $post) {
        try {
            $options = self::getOptions();
            
            if (self::shouldIgnore($comment, $options)) {
                return $comment;
            }
            
            self::sendPush(self::preparePushData($comment, $post, $options), $options);
        } catch (Exception $e) {
            Typecho_Plugin::error(_t('Comment2Bark 推送失败: %s', $e->getMessage()));
        }
        
        return $comment;
    }

    /** 获取配置 */
    private static function getOptions() {
        return Typecho_Widget::widget('Widget_Options')->plugin('Comment2Bark');
    }

    /** 检查是否忽略 */
    private static function shouldIgnore($comment, $options) {
        if ($options->ignoreSelf != '1') return false;
        
        $authorId = is_object($comment) ? ($comment->authorId ?? 0) : ($comment['authorId'] ?? 0);
        $adminUid = (int) ($options->adminUid ?: self::DEFAULT_ADMIN_UID);
        
        return (int) $authorId === $adminUid;
    }

    /** 准备推送数据 */
    private static function preparePushData($comment, $post, $options) {
        // finishComment 钩子传递的是 Widget_Feedback 对象
        // 该对象包含评论数据在 $row 属性中，且通过 __get 魔术方法可直接访问
        
        $coid = null;
        $cid = null;
        $author = '';
        $text = '';
        
        // 方式1: 直接作为对象属性访问 (Widget 通过 __get 魔术方法实现)
        if (is_object($comment)) {
            $coid = $comment->coid ?? null;
            $cid = $comment->cid ?? null;
            $author = $comment->author ?? '';
            $text = $comment->text ?? '';
            
            // 如果直接属性为空，尝试从 $row 数组获取
            if (empty($coid) && isset($comment->row)) {
                $row = $comment->row;
                $coid = $row['coid'] ?? null;
                $cid = $cid ?: ($row['cid'] ?? null);
                $author = $author ?: ($row['author'] ?? '');
                $text = $text ?: ($row['text'] ?? '');
            }
        }
        
        // 方式2: 如果是数组格式
        if (empty($coid) && is_array($comment)) {
            $coid = $comment['coid'] ?? null;
            $cid = $comment['cid'] ?? null;
            $author = $comment['author'] ?? '';
            $text = $comment['text'] ?? '';
        }
        
        // 备用方案: 如果 coid 仍然为空但 cid 有值，通过数据库查询最新评论
        if (empty($coid) && !empty($cid)) {
            try {
                $db = Typecho_Db::get();
                // 按 coid 降序获取该文章的最新评论
                $latestComment = $db->fetchRow($db->select()->from('table.comments')
                    ->where('cid = ?', $cid)
                    ->where('status = ?', self::STATUS_APPROVED)
                    ->order('coid', Typecho_Db::SORT_DESC)
                    ->limit(1));
                
                if ($latestComment) {
                    $coid = $latestComment['coid'];
                    $author = $author ?: ($latestComment['author'] ?? '');
                    $text = $text ?: ($latestComment['text'] ?? '');
                    self::log("通过数据库查询获取 coid={$coid}", $options);
                }
            } catch (Exception $e) {
                self::log("查询评论失败: " . $e->getMessage(), $options);
            }
        }
        
        // 获取文章信息
        $postTitle = '';
        $permalink = '';
        
        // 如果 comment 对象有 content 属性（Widget_Feedback 的 $content）
        if (is_object($comment) && isset($comment->content) && $comment->content) {
            $postTitle = $comment->content->title ?? '';
            $permalink = $comment->content->permalink ?? '';
        }
        
        // 如果 $post 参数有值
        if ($post) {
            if (is_object($post)) {
                $postTitle = $post->title ?? '';
                $permalink = $post->permalink ?? '';
            } elseif (is_array($post)) {
                $postTitle = $post['title'] ?? '';
                $permalink = $post['permalink'] ?? '';
            }
        }
        
        // 如果 $post 没有 permalink，通过 cid 查询
        if (empty($permalink) && $cid) {
            $postObj = Helper::widgetById('Contents', $cid);
            if ($postObj && $postObj->cid) {
                $postTitle = $postTitle ?: ($postObj->title ?? '');
                $permalink = $postObj->permalink ?? '';
            }
        }
        
        self::log("coid={$coid}, cid={$cid}, title={$postTitle}, permalink={$permalink}", $options);
        
        $siteName = Typecho_Widget::widget('Widget_Options')->title;
        
        return [
            'title'     => "站点「{$siteName}」有新评论",
            'body'      => "{$author} 在「{$postTitle}」中说：\n" . self::sanitizeText($text),
            'url'       => self::buildCommentUrl($coid, $cid, $permalink),
            'icon'      => $options->barkIcon,
            'group'     => $options->barkGroup,
            'isArchive' => $options->barkArchive,
            'sound'     => $options->barkSound,
            'level'     => 'active'
        ];
    }

    /** 清理文本 */
    private static function sanitizeText($text) {
        $text = strip_tags($text);
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\n+/', ' ', $text);
        return mb_strimwidth($text, 0, self::MAX_TEXT_LENGTH, '...');
    }

    /** 构建评论链接 */
    private static function buildCommentUrl($coid, $cid, $permalink) {
        if (empty($coid) || empty($cid) || empty($permalink)) {
            return $permalink ?: '';
        }
        
        $options = Typecho_Widget::widget('Widget_Options');
        
        // 清理 permalink 末尾的 / 避免双斜杠
        $permalink = rtrim($permalink, '/');
        
        if (!$options->commentsPageBreak) {
            return $permalink . '/#comment-' . $coid;
        }
        
        $page = self::calculateCommentPage($coid, $cid);
        
        return $permalink . '/comment-page-' . $page . '#comment-' . $coid;
    }

    /** 计算评论页码 */
    private static function calculateCommentPage($coid, $cid) {
        $options = Typecho_Widget::widget('Widget_Options');
        
        if (!$options->commentsPageBreak) return 1;
        
        $db = Typecho_Db::get();
        $select = $db->select(['COUNT(coid)' => 'num'])
            ->from('table.comments')
            ->where('cid = ? AND status = ? AND coid <= ?', $cid, self::STATUS_APPROVED, $coid);
        
        if ($options->commentsOrder == 'DESC') {
            $select->where('parent = ?', 0);
        }
        
        try {
            $count = $db->fetchObject($select)->num ?? 0;
            return max(1, ceil($count / $options->commentsPageSize));
        } catch (Exception $e) {
            return 1;
        }
    }

    /** 发送推送 */
    private static function sendPush($data, $options) {
        $barkKey = $options->barkKey;
        $barkServer = $options->barkServer ?: self::DEFAULT_SERVER;
        
        if (empty($barkKey) || !filter_var($barkServer, FILTER_VALIDATE_URL)) {
            return;
        }
        
        $data = array_filter($data, fn($v) => $v !== null && $v !== '');
        $url = rtrim($barkServer, '/') . '/' . $barkKey;
        
        self::log("发送请求: {$url}", $options);
        
        $retryDelay = 1;
        for ($i = 0; $i < self::MAX_RETRIES; $i++) {
            try {
                $result = function_exists('curl_init') 
                    ? self::sendWithCurl($url, $data, $options)
                    : self::sendWithFileGetContents($url, $data, $options);
                
                if ($result) return;
            } catch (Exception $e) {
                self::log('异常: ' . $e->getMessage(), $options);
            }
            
            if ($i < self::MAX_RETRIES - 1) {
                sleep($retryDelay);
                $retryDelay *= 2;
            }
        }
    }

    /** cURL 发送 */
    private static function sendWithCurl($url, $data, $options) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            self::log('cURL 错误: ' . curl_error($ch), $options);
        } else {
            self::log('响应: ' . $result, $options);
        }
        
        if (is_resource($ch)) curl_close($ch);
        
        return self::isSuccess($result);
    }

    /** file_get_contents 发送 */
    private static function sendWithFileGetContents($url, $data, $options) {
        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query($data), 'timeout' => 10],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        
        $result = file_get_contents($url, false, $context);
        self::log('响应: ' . $result, $options);
        
        return self::isSuccess($result);
    }

    /** 检查响应是否成功 */
    private static function isSuccess($result) {
        $response = json_decode($result, true);
        return isset($response['code']) && $response['code'] === 200;
    }

    /** 记录日志 */
    private static function log($message, $options) {
        if ($options->debug == '1') {
            error_log('Comment2Bark: ' . $message);
        }
    }
}
