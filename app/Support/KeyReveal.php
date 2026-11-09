<?php
declare(strict_types=1);

namespace App\Support;

/**
 * API KEY 的一次性展示。
 *
 * 明文 KEY 只在「创建服务器」或「重置 KEY」之后的那一次页面渲染中出现：
 * 控制器把它放进会话，详情页取走后立即删除，因此刷新页面就再也看不到了。
 *
 * 之所以还要加过期时间：避免用户创建后没打开详情页，明文在会话里长期滞留。
 * 会话文件本身也属于磁盘数据，暴露面越小越好。
 */
final class KeyReveal
{
    private const SESSION_KEY = 'key_reveal';

    /** 明文在会话里的最长存活时间（秒） */
    private const TTL = 600;

    public static function put(int $serverId, string $apiKey): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'server_id' => $serverId,
            'key'       => $apiKey,
            'expire_at' => time() + self::TTL,
        ];
    }

    /**
     * 取出并销毁明文 KEY。
     *
     * 服务器 ID 不匹配时不销毁（用户可能只是先点了别的服务器），
     * 但仍然受 TTL 限制，过期后一律作废。
     */
    public static function pull(int $serverId): ?string
    {
        $payload = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        if (time() > (int) ($payload['expire_at'] ?? 0)) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        if ((int) ($payload['server_id'] ?? 0) !== $serverId) {
            return null;
        }

        unset($_SESSION[self::SESSION_KEY]);

        $key = $payload['key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
