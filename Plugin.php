<?php
/**
 * Comment2Bark - Typecho 评论 Bark 推送插件
 *
 * @package Comment2Bark
 * @author  蛋蛋之家
 * @version 2.0.0
 * @link    https://github.com/NoEggEgg/Comment2Bark
 */

class Comment2Bark_Plugin implements Typecho_Plugin_Interface
{
    // ==================== 常量 ====================
    private const MAX_RETRIES = 3;
    private const MAX_TEXT_LENGTH = 150;
    private const DEFAULT_SERVER = 'https://api.day.app/';
    private const DEFAULT_ADMIN_UID = 1;

    // 评论状态
    private const STATUS_APPROVED = 'approved';
    private const STATUS_WAITING  = 'waiting';
    private const STATUS_SPAM     = 'spam';

    // 评论类型
    private const TYPE_COMMENT   = 'comment';
    private const TYPE_TRACKBACK = 'trackback';
    private const TYPE_PINGBACK = 'pingback';

    // 推送级别
    private const LEVEL_ACTIVE = 'active';
    private const LEVEL_TIME_SENSITIVE = 'timeSensitive';

    // ==================== 插件生命周期 ====================

    public static function activate(): string
    {
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = [self::class, 'onFinishComment'];
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark = [self::class, 'onMark'];
        return _t('请配置 Bark Key 以启用推送');
    }

    public static function deactivate(): void {}

    // ==================== 配置面板 ====================

    public static function config(Typecho_Widget_Helper_Form $form): void
    {
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'barkServer', null, self::DEFAULT_SERVER,
            _t('服务器地址'), _t('默认使用官方服务器 https://api.day.app/')
        ));

        $key = new Typecho_Widget_Helper_Form_Element_Text('barkKey', null, null, _t('Bark Key'), _t('必填项，从 Bark App 中获取'));
        $key->addRule('required', _t('请填写 Bark Key'));
        $form->addInput($key);

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('barkArchive', [1 => '保存', 0 => '不保存'], 1, _t('消息保存'), _t('推送后是否在 Bark 中保存')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('adminUid', null, 1, _t('博主 UID'), _t('默认为 1')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('ignoreSelf', [1 => '是', 0 => '否'], 0, _t('忽略自己'), _t('忽略博主本人的评论')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('barkIcon', null, null, _t('推送图标'), _t('可选，图片 URL')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('barkGroup', null, null, _t('消息分组'), _t('可选，Bark 消息分组名称')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('barkSound', null, null, _t('提示音'), _t('可选，Bark 提示音名称')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('debug', [1 => '启用', 0 => '禁用'], 0, _t('调试模式'), _t('启用后记录详细日志')));
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form): void {}

    // ==================== 钩子处理 ====================

    /**
     * finishComment 钩子 - 评论提交后立即触发（评论已入库，coid 可用）
     */
    public static function onFinishComment($comment): void
    {
        $options = self::getOptions();

        if (!is_object($comment)) {
            self::log("评论数据异常", $options);
            return;
        }

        $coid   = $comment->coid ?? 0;
        $cid    = $comment->cid ?? 0;
        $status = $comment->status ?? '';
        $parent = $comment->parent ?? 0;
        $authorId = $comment->authorId ?? 0;

        self::log("评论完成: coid={$coid}, status={$status}, parent={$parent}", $options);

        // 忽略博主自己
        if ($options->ignoreSelf && $authorId === ((int) $options->adminUid ?: self::DEFAULT_ADMIN_UID)) {
            return;
        }

        try {
            match ($status) {
                self::STATUS_APPROVED => $parent == 0
                    ? self::pushNewComment($coid, $cid, $options)
                    : self::pushReply($coid, $cid, $parent, $options),
                self::STATUS_WAITING => self::pushWaiting($coid, $cid, $options),
                self::STATUS_SPAM => self::pushSpam($coid, $cid, $comment->type ?? self::TYPE_COMMENT, $options),
                default => null
            };
        } catch (Exception $e) {
            Typecho_Plugin::error(_t('Comment2Bark 推送失败: %s', $e->getMessage()));
        }
    }

    /**
     * onMark 钩子 - 后台评论审核处理
     */
    public static function onMark($comment, $edit, string $status)
    {
        $options = self::getOptions();
        $coid = $comment->coid ?? 0;

        if (empty($coid) || $status !== self::STATUS_APPROVED) {
            return $comment;
        }

        self::log("审核通过: coid={$coid}", $options);

        try {
            $pushData = self::buildApprovedNotice($coid, $options);
            $pushData && self::sendPush($pushData, $options);
        } catch (Exception $e) {
            Typecho_Plugin::error(_t('Comment2Bark 审核通知失败: %s', $e->getMessage()));
        }

        return $comment;
    }

    // ==================== 推送场景 ====================

    /**
     * 场景1: 新评论
     * 格式: 📩 {博客名}有新评论「{文章}」{评论者}：{内容}
     */
    private static function pushNewComment(int $coid, int $cid, object $options): void
    {
        $comment = self::getCommentWithPost($coid);
        if (!$comment) {
            return;
        }

        $data = self::buildCommentData(
            $options,
            "📩 【" . self::getSiteName() . "】有新评论",
            $comment['post_title'] ?? '',
            $comment['author'] ?? '',
            $comment['text'] ?? '',
            self::getCommentUrl($coid, $cid),
            self::LEVEL_ACTIVE
        );

        self::sendPush($data, $options);
    }

    /**
     * 场景2: 回复通知
     * 格式: 💬 {被回复者}的评论被回复「{原评论摘要}」{回复者}：{内容}
     */
    private static function pushReply(int $coid, int $cid, int $parent, object $options): void
    {
        $comment = self::getCommentWithPost($coid);
        $parentComment = self::getComment($parent);

        if (!$comment || !$parentComment) {
            return;
        }

        $parentText = mb_substr(strip_tags($parentComment['text'] ?? ''), 0, 20);
        $body = "回复：「{$parentText}」\n";
        $body .= "👤 " . ($comment['author'] ?? '') . "：" . self::sanitizeText($comment['text'] ?? '');

        $data = self::buildPushData(
            "💬 " . ($parentComment['author'] ?? '') . "的评论被回复",
            $body,
            self::getCommentUrl($coid, $cid),
            self::LEVEL_ACTIVE,
            $options
        );

        self::sendPush($data, $options);
    }

    /**
     * 场景3: 待审核评论
     * 格式: ⏳ {博客名}有「待审」评论「{文章}」{评论者}：{内容}
     */
    private static function pushWaiting(int $coid, int $cid, object $options): void
    {
        $comment = self::getCommentWithPost($coid);
        if (!$comment) {
            return;
        }

        $data = self::buildCommentData(
            $options,
            "⏳ 【" . self::getSiteName() . "】有「待审」评论",
            $comment['post_title'] ?? '',
            $comment['author'] ?? '',
            $comment['text'] ?? '',
            self::getAdminUrl(),
            self::LEVEL_TIME_SENSITIVE
        );

        self::sendPush($data, $options);
    }

    /**
     * 场景4: 垃圾评论
     * 格式: 🗑️ {博客名}有「垃圾」{类型}「{文章}」{评论者}：{内容}
     */
    private static function pushSpam(int $coid, int $cid, string $type, object $options): void
    {
        $comment = self::getCommentWithPost($coid);
        if (!$comment) {
            return;
        }

        $typeLabel = match ($type) {
            self::TYPE_TRACKBACK => '引用',
            self::TYPE_PINGBACK => 'Pingback',
            default => '评论'
        };

        $data = self::buildCommentData(
            $options,
            "🗑️ 【" . self::getSiteName() . "】有「垃圾」{$typeLabel}",
            $comment['post_title'] ?? '',
            $comment['author'] ?? '',
            $comment['text'] ?? '',
            self::getAdminUrl(),
            self::LEVEL_TIME_SENSITIVE
        );

        self::sendPush($data, $options);
    }

    /**
     * 场景5: 后台审核通过
     */
    private static function buildApprovedNotice(int $coid, object $options): ?array
    {
        $comment = self::getCommentWithPost($coid);
        if (!$comment) {
            return null;
        }

        $cid = $comment['cid'] ?? 0;

        return self::buildCommentData(
            $options,
            "✅ 【" . self::getSiteName() . "】评论审核已通过",
            $comment['post_title'] ?? '',
            $comment['author'] ?? '',
            $comment['text'] ?? '',
            self::getCommentUrl($coid, $cid),
            self::LEVEL_ACTIVE
        );
    }

    // ==================== 辅助方法 ====================

    private static function getOptions(): object
    {
        return Typecho_Widget::widget('Widget_Options')->plugin('Comment2Bark');
    }

    private static function getSiteName(): string
    {
        return Typecho_Widget::widget('Widget_Options')->title;
    }

    private static function getWidgetOptions(): Typecho_Widget
    {
        return Typecho_Widget::widget('Widget_Options');
    }

    /**
     * 获取评论及文章信息（合并查询）
     */
    private static function getCommentWithPost(int $coid): ?array
    {
        if (empty($coid)) {
            return null;
        }

        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('c.*', 'p.title as post_title')
                 ->from('table.comments c')
                 ->join('table.contents p', 'c.cid = p.cid', Typecho_Db::LEFT_JOIN)
                 ->where('c.coid = ?', $coid)
                 ->limit(1)
            );
            return $row ?: null;
        } catch (Exception $e) {
            error_log('[Comment2Bark] getCommentWithPost 失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取单条评论
     */
    private static function getComment(int $coid): ?array
    {
        if (empty($coid)) {
            return null;
        }

        try {
            $db = Typecho_Db::get();
            return $db->fetchRow(
                $db->select('author', 'text')
                 ->from('table.comments')
                 ->where('coid = ?', $coid)
                 ->limit(1)
            );
        } catch (Exception) {
            return null;
        }
    }

    /**
     * 获取评论链接（支持分页）
     */
    private static function getCommentUrl(int $coid, int $cid): string
    {
        $options = self::getWidgetOptions();
        $permalink = self::getPostPermalink($cid);

        if (empty($permalink)) {
            return rtrim($options->siteUrl, '/');
        }

        $permalink = rtrim($permalink, '/');

        if (empty($coid) || empty($cid)) {
            return $permalink;
        }

        // 无分页时直接锚点定位
        if (empty($options->commentsPageBreak)) {
            return $permalink . '/#comment-' . $coid;
        }

        // 计算页码
        $page = self::calculateCommentPage($coid, $cid, $options);
        return $permalink . '/comment-page-' . $page . '#comment-' . $coid;
    }

    /**
     * 获取文章永久链接
     */
    private static function getPostPermalink(int $cid): string
    {
        if (empty($cid)) {
            return '';
        }

        try {
            $archive = Typecho_Widget::widget('Widget_Archive', 'cid=' . $cid, '_single=1');
            return $archive && $archive->cid ? rtrim($archive->permalink, '/') : '';
        } catch (Exception $e) {
            error_log('[Comment2Bark] getPostPermalink 失败: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 计算评论所在页码
     */
    private static function calculateCommentPage(int $coid, int $cid, object $options): int
    {
        if (empty($options->commentsPageBreak)) {
            return 1;
        }

        try {
            $db = Typecho_Db::get();
            // 分页按顶级评论计算，parent=0
            $select = $db->select(['COUNT(coid)' => 'num'])
                ->from('table.comments')
                ->where('cid = ? AND status = ? AND parent = 0', $cid, self::STATUS_APPROVED);

            if (!empty($options->commentsOrder) && $options->commentsOrder === 'DESC') {
                $select->where('coid >= ?', $coid);
            } else {
                $select->where('coid <= ?', $coid);
            }

            $count = $db->fetchObject($select)->num ?? 0;
            return max(1, (int) ceil($count / ($options->commentsPageSize ?: 20)));
        } catch (Exception) {
            return 1;
        }
    }

    private static function getAdminUrl(): string
    {
        return rtrim(self::getWidgetOptions()->siteUrl, '/') . '/admin/manage-comments.php';
    }

    /**
     * 构建评论消息体
     */
    private static function buildCommentData(
        object $options,
        string $title,
        string $postTitle,
        string $author,
        string $text,
        string $url,
        string $level
    ): array {
        $body = "「{$postTitle}」\n";
        $body .= "👤 {$author}：" . self::sanitizeText($text);

        return self::buildPushData($title, $body, $url, $level, $options);
    }

    /**
     * 构建推送数据
     */
    private static function buildPushData(string $title, string $body, string $url, string $level, object $options): array
    {
        return [
            'title'     => $title,
            'body'      => $body,
            'url'       => $url,
            'icon'      => filter_var($options->barkIcon ?? '', FILTER_VALIDATE_URL) ? $options->barkIcon : '',
            'group'     => $options->barkGroup ?? '',
            'isArchive' => $options->barkArchive ?? 1,
            'sound'     => $options->barkSound ?? '',
            'level'     => $level,
        ];
    }

    private static function sanitizeText(string $text): string
    {
        $text = strip_tags($text);
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return mb_strimwidth(preg_replace('/\s+/', ' ', $text), 0, self::MAX_TEXT_LENGTH, '...');
    }

    // ==================== 推送核心 ====================

    private static function sendPush(array $data, object $options): void
    {
        $barkKey = $options->barkKey ?? '';
        $barkServer = $options->barkServer ?: self::DEFAULT_SERVER;

        if (empty($barkKey) || !filter_var($barkServer, FILTER_VALIDATE_URL)) {
            self::log("配置不完整，跳过推送", $options);
            return;
        }

        $data = array_filter($data, fn($v) => $v !== null && $v !== '');
        $url = rtrim($barkServer, '/') . '/' . $barkKey;

        self::log("推送: {$data['title']}", $options);

        $retryDelay = 1;
        for ($i = 0; $i < self::MAX_RETRIES; $i++) {
            try {
                $result = function_exists('curl_init')
                    ? self::requestWithCurl($url, $data)
                    : self::requestWithFgc($url, $data);
                if ($result) return;
            } catch (Exception $e) {
                self::log("请求异常: " . $e->getMessage(), $options);
            }

            if ($i < self::MAX_RETRIES - 1) {
                sleep($retryDelay);
                $retryDelay *= 2;
            }
        }
    }

    private static function requestWithCurl(string $url, array $data): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
        ]);

        $result = curl_exec($ch);
        curl_errno($ch) && error_log('Comment2Bark cURL 错误: ' . curl_error($ch));
        curl_close($ch);

        return self::isSuccess($result);
    }

    private static function requestWithFgc(string $url, array $data): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data),
                'timeout' => 10,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        return self::isSuccess(file_get_contents($url, false, $context));
    }

    private static function isSuccess($result): bool
    {
        if ($result === false) return false;
        $response = json_decode($result, true);
        return isset($response['code']) && $response['code'] === 200;
    }

    private static function log(string $message, object $options): void
    {
        if (!empty($options->debug)) {
            error_log('[Comment2Bark] ' . $message);
        }
    }
}
