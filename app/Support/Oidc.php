<?php
declare(strict_types=1);

namespace App\Support;

/**
 * OpenID Connect 客户端（Authorization Code + PKCE）。
 *
 * 零依赖实现：
 *   - 通过 {issuer}/.well-known/openid-configuration 自动发现端点；
 *   - 用 state + nonce + PKCE(S256) 防止 CSRF、重放与授权码拦截；
 *   - 校验 id_token 的 RS 系列与 ES 系列签名（基于 JWKS 现场构造公钥），
 *     并校验 iss / aud / exp / nonce；
 *   - 只允许配置中 allowed_subs 名单内的 sub 登录。
 */
final class Oidc
{
    private const SESSION_KEY = 'oidc_flow';
    private const HTTP_TIMEOUT = 10;
    private const FLOW_TTL = 600;
    private const LEEWAY = 60;

    /**
     * 允许的 id_token 签名算法。
     *
     * 只支持非对称算法；"none" 与 HS* 一律拒绝（HS* 会让客户端密钥
     * 变成签名密钥，公有客户端根本不该用它签发 id_token）。
     * ES* 的签名在 JWS 里是 R||S 定长拼接，需要先转成 DER 才能交给 openssl。
     */
    private const ALGORITHMS = [
        'RS256' => ['kty' => 'RSA', 'digest' => OPENSSL_ALGO_SHA256],
        'RS384' => ['kty' => 'RSA', 'digest' => OPENSSL_ALGO_SHA384],
        'RS512' => ['kty' => 'RSA', 'digest' => OPENSSL_ALGO_SHA512],
        'ES256' => ['kty' => 'EC', 'digest' => OPENSSL_ALGO_SHA256, 'crv' => 'P-256', 'size' => 32],
        'ES384' => ['kty' => 'EC', 'digest' => OPENSSL_ALGO_SHA384, 'crv' => 'P-384', 'size' => 48],
        'ES512' => ['kty' => 'EC', 'digest' => OPENSSL_ALGO_SHA512, 'crv' => 'P-521', 'size' => 66],
    ];

    public static function enabled(): bool
    {
        return (bool) Config::get('oidc.enabled', false)
            && self::issuer() !== ''
            && self::clientId() !== ''
            && self::allowedSubs() !== [];
    }

    public static function buttonLabel(): string
    {
        $label = trim((string) Config::get('oidc.button_label', ''));
        return $label !== '' ? $label : '使用 OIDC 登录';
    }

    public static function redirectUri(): string
    {
        $configured = trim((string) Config::get('oidc.redirect_uri', ''));
        return $configured !== '' ? $configured : Url::absolute('auth/oidc/callback');
    }

    /** 发起登录：生成一次性参数并跳转到身份提供方 */
    public static function begin(): void
    {
        $discovery = self::discover();

        $authorizationEndpoint = (string) ($discovery['authorization_endpoint'] ?? '');
        if ($authorizationEndpoint === '') {
            throw new \RuntimeException('OIDC 发现文档缺少 authorization_endpoint');
        }

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = self::base64UrlEncode(random_bytes(48));
        $challenge = self::base64UrlEncode(hash('sha256', $verifier, true));

        $_SESSION[self::SESSION_KEY] = [
            'state'       => $state,
            'nonce'       => $nonce,
            'verifier'    => $verifier,
            'created_at'  => time(),
        ];

        $params = [
            'response_type'         => 'code',
            'client_id'             => self::clientId(),
            'redirect_uri'          => self::redirectUri(),
            'scope'                 => self::scopes(),
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $separator = str_contains($authorizationEndpoint, '?') ? '&' : '?';

        Response::redirect(
            $authorizationEndpoint . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
        );
    }

    /**
     * 处理回调：校验 state、交换令牌、校验 id_token、检查 sub 白名单。
     *
     * @return array<string, mixed> id_token 载荷
     */
    public static function handleCallback(Request $request): array
    {
        $flow = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        $errorRaw = $request->query('error', '');
        $error = is_string($errorRaw) ? $errorRaw : '';
        if ($error !== '') {
            $descriptionRaw = $request->query('error_description', '');
            $description = is_string($descriptionRaw) ? trim($descriptionRaw) : '';
            throw new \RuntimeException('身份提供方返回错误：' . $error . ($description !== '' ? ' - ' . $description : ''));
        }

        if (!is_array($flow) || !isset($flow['state'], $flow['nonce'], $flow['verifier'])) {
            throw new \RuntimeException('登录会话已过期，请重新登录');
        }
        if ((time() - (int) ($flow['created_at'] ?? 0)) > self::FLOW_TTL) {
            throw new \RuntimeException('登录会话已过期，请重新登录');
        }

        $stateRaw = $request->query('state', '');
        $state = is_string($stateRaw) ? $stateRaw : '';
        if ($state === '' || !hash_equals((string) $flow['state'], $state)) {
            throw new \RuntimeException('state 校验失败');
        }

        $codeRaw = $request->query('code', '');
        $code = is_string($codeRaw) ? $codeRaw : '';
        if ($code === '') {
            throw new \RuntimeException('未收到授权码');
        }

        $discovery = self::discover();
        $tokenEndpoint = (string) ($discovery['token_endpoint'] ?? '');
        if ($tokenEndpoint === '') {
            throw new \RuntimeException('OIDC 发现文档缺少 token_endpoint');
        }

        $token = self::requestToken($tokenEndpoint, $discovery, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::redirectUri(),
            'client_id'     => self::clientId(),
            'code_verifier' => (string) $flow['verifier'],
        ]);

        $idToken = (string) ($token['id_token'] ?? '');
        if ($idToken === '') {
            throw new \RuntimeException('身份提供方未返回 id_token');
        }

        $claims = self::verifyIdToken($idToken, $discovery, (string) $flow['nonce']);

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            throw new \RuntimeException('id_token 缺少 sub');
        }
        if (!in_array($sub, self::allowedSubs(), true)) {
            throw new \RuntimeException('当前账号（sub: ' . $sub . '）不在允许登录名单内');
        }

        return $claims;
    }

    private static function issuer(): string
    {
        return rtrim(trim((string) Config::get('oidc.issuer', '')), '/');
    }

    private static function clientId(): string
    {
        return trim((string) Config::get('oidc.client_id', ''));
    }

    private static function clientSecret(): string
    {
        return (string) Config::get('oidc.client_secret', '');
    }

    /** @return string[] */
    private static function allowedSubs(): array
    {
        $subs = Config::get('oidc.allowed_subs', []);
        if (is_string($subs)) {
            $subs = preg_split('/[\s,]+/', $subs) ?: [];
        }

        $allowed = [];
        foreach ((array) $subs as $sub) {
            $sub = trim((string) $sub);
            if ($sub !== '' && !in_array($sub, $allowed, true)) {
                $allowed[] = $sub;
            }
        }

        return $allowed;
    }

    private static function scopes(): string
    {
        $scopes = preg_split('/[\s,]+/', trim((string) Config::get('oidc.scopes', 'openid profile email'))) ?: [];
        $scopes = array_values(array_unique(array_filter($scopes, static fn (string $s): bool => $s !== '')));
        if (!in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }
        return implode(' ', $scopes);
    }

    /** @return array<string, mixed> */
    private static function discover(): array
    {
        $document = self::fetchJson(self::issuer() . '/.well-known/openid-configuration');

        $documentIssuer = rtrim((string) ($document['issuer'] ?? ''), '/');
        if ($documentIssuer !== '' && $documentIssuer !== self::issuer()) {
            throw new \RuntimeException('OIDC 发现文档的 issuer 与配置不一致');
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $discovery
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private static function requestToken(string $endpoint, array $discovery, array $form): array
    {
        $secret = self::clientSecret();
        if ($secret === '') {
            return self::parseTokenResponse(...self::httpPostForm($endpoint, $form, []));
        }

        $methods = $discovery['token_endpoint_auth_methods_supported'] ?? [];
        $methods = is_array($methods) ? $methods : [];

        $basicHeaders = ['Authorization: Basic ' . base64_encode(self::clientId() . ':' . $secret)];

        $useBasic = $methods === [] || in_array('client_secret_basic', $methods, true);

        if ($useBasic) {
            [$status, $body] = self::httpPostForm($endpoint, $form, $basicHeaders);
            if ($status >= 200 && $status < 300) {
                return self::parseTokenResponse($status, $body);
            }
            if (in_array('client_secret_post', $methods, true)) {
                $form['client_secret'] = $secret;
                return self::parseTokenResponse(...self::httpPostForm($endpoint, $form, []));
            }
            return self::parseTokenResponse($status, $body);
        }

        if (in_array('client_secret_post', $methods, true)) {
            $form['client_secret'] = $secret;
            return self::parseTokenResponse(...self::httpPostForm($endpoint, $form, []));
        }

        // 未声明支持方式时按规范默认 client_secret_basic
        return self::parseTokenResponse(...self::httpPostForm($endpoint, $form, $basicHeaders));
    }

    /** @return array<string, mixed> */
    private static function parseTokenResponse(int $status, string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('令牌接口返回非 JSON 响应（HTTP ' . $status . '）');
        }
        if ($status < 200 || $status >= 300 || isset($data['error'])) {
            $message = (string) ($data['error_description'] ?? $data['error'] ?? ('HTTP ' . $status));
            throw new \RuntimeException('令牌交换失败：' . $message);
        }
        return $data;
    }

    /**
     * 校验 id_token：签名 + iss / aud / exp / nonce。
     *
     * @param array<string, mixed> $discovery
     * @return array<string, mixed>
     */
    private static function verifyIdToken(string $jwt, array $discovery, string $expectedNonce): array
    {
        if (!function_exists('openssl_verify')) {
            throw new \RuntimeException('缺少 openssl 扩展，无法校验 id_token 签名');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('id_token 格式无效');
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(self::base64UrlDecode($headerB64), true);
        if (!is_array($header)) {
            throw new \RuntimeException('id_token 头部无效');
        }

        $alg = (string) ($header['alg'] ?? '');
        if (!isset(self::ALGORITHMS[$alg])) {
            throw new \RuntimeException('不支持的 id_token 签名算法：' . ($alg === '' ? '(缺失)' : $alg));
        }
        $algorithm = self::ALGORITHMS[$alg];

        // 身份提供方若声明了支持的算法，则必须包含当前算法
        $supported = $discovery['id_token_signing_alg_values_supported'] ?? null;
        if (is_array($supported) && $supported !== [] && !in_array($alg, $supported, true)) {
            throw new \RuntimeException('身份提供方未声明支持该签名算法：' . $alg);
        }

        $jwksUri = (string) ($discovery['jwks_uri'] ?? '');
        if ($jwksUri === '') {
            throw new \RuntimeException('OIDC 发现文档缺少 jwks_uri');
        }

        $key = self::selectJwk(self::fetchJson($jwksUri), (string) ($header['kid'] ?? ''), $algorithm);
        if ($key === null) {
            throw new \RuntimeException('未找到匹配的签名公钥（' . $alg . '）');
        }

        $signature = self::base64UrlDecode($signatureB64);
        if ($algorithm['kty'] === 'EC') {
            $signature = self::ecdsaSignatureToDer($signature, $algorithm['size'], $alg);
        }

        $verified = openssl_verify(
            $headerB64 . '.' . $payloadB64,
            $signature,
            self::jwkToPem($key),
            $algorithm['digest']
        );
        if ($verified !== 1) {
            throw new \RuntimeException('id_token 签名校验失败');
        }

        $claims = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($claims)) {
            throw new \RuntimeException('id_token 载荷无效');
        }

        $issuer = rtrim((string) ($discovery['issuer'] ?? self::issuer()), '/');
        $iss = rtrim((string) ($claims['iss'] ?? ''), '/');
        if ($iss === '' || $iss !== $issuer) {
            throw new \RuntimeException('id_token 签发方不匹配');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = array_map(
            'strval',
            array_filter(is_array($aud) ? $aud : [$aud], static fn ($v): bool => $v !== null)
        );
        if (!in_array(self::clientId(), $audiences, true)) {
            throw new \RuntimeException('id_token 受众不匹配');
        }

        if (is_array($aud) && count($aud) > 1 && isset($claims['azp'])
            && (string) $claims['azp'] !== self::clientId()) {
            throw new \RuntimeException('id_token azp 不匹配');
        }

        $now = time();
        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int) $claims['exp'] + self::LEEWAY < $now) {
            throw new \RuntimeException('id_token 已过期');
        }
        if (isset($claims['iat']) && is_numeric($claims['iat']) && (int) $claims['iat'] - self::LEEWAY > $now) {
            throw new \RuntimeException('id_token 签发时间异常');
        }
        if (!isset($claims['nonce']) || !hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new \RuntimeException('nonce 校验失败');
        }

        return $claims;
    }

    /**
     * 按 kid 选出与签名算法匹配的公钥。
     *
     * @param array<string, mixed> $jwks
     * @param array<string, mixed> $algorithm
     * @return array<string, mixed>|null
     */
    private static function selectJwk(array $jwks, string $kid, array $algorithm): ?array
    {
        $keys = $jwks['keys'] ?? [];
        if (!is_array($keys)) {
            return null;
        }

        $fallback = null;
        foreach ($keys as $key) {
            if (!is_array($key) || (string) ($key['kty'] ?? '') !== $algorithm['kty']) {
                continue;
            }
            if (isset($key['use']) && (string) $key['use'] !== 'sig') {
                continue;
            }
            if (isset($key['alg']) && (string) $key['alg'] !== '') {
                // 公钥声明了用途算法时要求一致，避免把 RSA 密钥当 EC 用
                if ((string) $key['alg'] !== self::algOf($algorithm)) {
                    continue;
                }
            }
            if ($algorithm['kty'] === 'EC') {
                if ((string) ($key['crv'] ?? '') !== $algorithm['crv']) {
                    continue;
                }
            }

            if ($kid !== '') {
                if ((string) ($key['kid'] ?? '') === $kid) {
                    return $key;
                }
                continue;
            }

            if ($fallback === null) {
                $fallback = $key;
            }
        }

        return $fallback;
    }

    /** @param array<string, mixed> $algorithm */
    private static function algOf(array $algorithm): string
    {
        foreach (self::ALGORITHMS as $name => $definition) {
            if ($definition === $algorithm) {
                return $name;
            }
        }
        return '';
    }

    /** @param array<string, mixed> $jwk */
    private static function jwkToPem(array $jwk): string
    {
        $kty = (string) ($jwk['kty'] ?? '');

        if ($kty === 'EC') {
            $curve = (string) ($jwk['crv'] ?? '');
            // 曲线 OID 与坐标字节数（P-521 是 66 字节，不是 64）
            $curves = [
                'P-256' => ["\x2a\x86\x48\xce\x3d\x03\x01\x07", 32],
                'P-384' => ["\x2b\x81\x04\x00\x22", 48],
                'P-521' => ["\x2b\x81\x04\x00\x23", 66],
            ];
            if (!isset($curves[$curve])) {
                throw new \RuntimeException('不支持的 EC 曲线：' . $curve);
            }
            [$curveOid, $size] = $curves[$curve];

            $x = self::base64UrlDecode((string) ($jwk['x'] ?? ''));
            $y = self::base64UrlDecode((string) ($jwk['y'] ?? ''));
            if ($x === '' || $y === '') {
                throw new \RuntimeException('JWK 缺少 EC 参数');
            }

            // JWK 要求坐标是定长的；部分提供方会省略前导零，这里补齐
            $point = "\x04"
                . str_pad($x, $size, "\x00", STR_PAD_LEFT)
                . str_pad($y, $size, "\x00", STR_PAD_LEFT);

            $algorithm = self::derSequence(
                self::derElement(0x06, "\x2a\x86\x48\xce\x3d\x02\x01") . self::derElement(0x06, $curveOid)
            );
            $subjectPublicKeyInfo = self::derSequence($algorithm . self::derElement(0x03, "\x00" . $point));

            return "-----BEGIN PUBLIC KEY-----\n"
                . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
                . "-----END PUBLIC KEY-----\n";
        }

        $modulus = self::base64UrlDecode((string) ($jwk['n'] ?? ''));
        $exponent = self::base64UrlDecode((string) ($jwk['e'] ?? ''));
        if ($modulus === '' || $exponent === '') {
            throw new \RuntimeException('JWK 缺少 RSA 参数');
        }

        $rsaPublicKey = self::derSequence(self::derInteger($modulus) . self::derInteger($exponent));
        $algorithm = self::derSequence(
            self::derElement(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00"
        );
        $subjectPublicKeyInfo = self::derSequence($algorithm . self::derElement(0x03, "\x00" . $rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * JWS 里的 ECDSA 签名是 R||S 定长拼接（无 DER 头），
     * openssl_verify 要的是 DER SEQUENCE{ INTEGER r, INTEGER s }，这里做转换。
     */
    private static function ecdsaSignatureToDer(string $signature, int $size, string $alg): string
    {
        if (strlen($signature) !== $size * 2) {
            throw new \RuntimeException($alg . ' 签名长度无效');
        }

        $r = substr($signature, 0, $size);
        $s = substr($signature, $size);

        return self::derSequence(self::derInteger($r) . self::derInteger($s));
    }

    private static function derElement(int $tag, string $value): string
    {
        return chr($tag) . self::derLength(strlen($value)) . $value;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return self::derElement(0x02, $bytes);
    }

    private static function derSequence(string $value): string
    {
        return self::derElement(0x30, $value);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $padding = strlen($data) % 4;
        if ($padding !== 0) {
            $data .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new \RuntimeException('Base64URL 解码失败');
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function fetchJson(string $url): array
    {
        [$status, $body] = self::httpRequest('GET', $url, ['Accept: application/json'], null);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('请求 ' . $url . ' 失败（HTTP ' . $status . '）');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('响应不是有效 JSON：' . $url);
        }
        return $data;
    }

    /**
     * @param string[] $headers
     * @param array<string, string> $form
     * @return array{0:int,1:string}
     */
    private static function httpPostForm(string $url, array $form, array $headers): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Accept: application/json';

        return self::httpRequest(
            'POST',
            $url,
            $headers,
            http_build_query($form, '', '&', PHP_QUERY_RFC3986)
        );
    }

    /**
     * @param string[] $headers
     * @return array{0:int,1:string}
     */
    private static function httpRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('不支持的 URL 协议：' . $url);
        }

        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new \RuntimeException('无法初始化 HTTP 请求');
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CUSTOMREQUEST  => $method,
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }

            curl_setopt_array($handle, $options);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            // 不调用 curl_close()：PHP 8.0 起句柄是对象，离开作用域即释放，
            // 而该函数在 PHP 8.5 已被弃用，调用会触发 Deprecated 提示。

            if ($response === false) {
                throw new \RuntimeException('HTTP 请求失败：' . $error);
            }
            return [$status, (string) $response];
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('服务器未启用 cURL 且禁用了 allow_url_fopen，无法完成 OIDC 请求');
        }

        $context = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headers),
                'content'         => $body ?? '',
                'timeout'         => self::HTTP_TIMEOUT,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        if ($response === false) {
            throw new \RuntimeException('HTTP 请求失败：' . $url);
        }
        return [$status, $response];
    }
}
