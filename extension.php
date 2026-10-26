<?php
/**
 * xExtension-NoRetry
 *
 * 目的：让指定域名的订阅不参与 FreshRSS 的 Retry-After 退避机制。
 *
 * 背景：
 *   FreshRSS 收到 429/503 或超时时，会在 data/Retry-After/ 下建一个
 *   以【域名】命名的空文件，文件 mtime 就是退避到期时间。
 *   而且它是域名级的 —— 一个路由失败会连累同域名下所有订阅。
 *
 * 限制：
 *   退避检查在 FreshRSS_http_Util::httpGet() 内部，发生在
 *   SimplepieBeforeInit 等 hook 之前，扩展无法在那一刻拦截。
 *
 * 解法：
 *   注册 freshrss_init hook（每次已登录请求都会触发），
 *   在请求早期就把白名单域名的 Retry-After 文件删掉。
 *   文件不存在 → getRetryAfter() 返回 0 → 不触发退避。
 *
 * 注意：不改任何 FreshRSS 源码，纯扩展实现，镜像升级不受影响。
 */
class NoRetryExtension extends Minz_Extension {

	/** Retry-After 目录（相对 FreshRSS 的 data 目录） */
	private const RETRY_DIR_REL = '/Retry-After';

	/** 用户配置键名 */
	public const CONF_KEY = 'noretry_whitelist';

	/** 默认白名单 */
	private const DEFAULT_LIST = "rsshub.flyneko.com";

	/** 默认白名单（多语言 key 前缀） */
	public const I18N_PREFIX = 'ext.noretry.';

	public function init(): void {
		if (!FreshRSS_Context::hasSystemConf()) {
			throw new FreshRSS_Context_Exception('System configuration not initialised!');
		}

		// 加载 i18n/xx/ext.php
		$this->registerTranslates();

		// 每次已登录请求都跑一次，保证退避文件刚生成就被清掉
		$this->registerHook('freshrss_init', [self::class, 'clearWhitelistedRetryAfter']);
	}

	/**
	 * 读取白名单（用户配置优先，未配置则用默认）
	 * 返回去重后的域名数组
	 */
	public static function getWhitelist(): array {
		$raw = null;
		try {
			$raw = FreshRSS_Context::userConf()->attributeString(self::CONF_KEY);
		} catch (Throwable $e) {
			$raw = null;
		}
		if ($raw === null || trim($raw) === '') {
			$raw = self::DEFAULT_LIST;
		}

		$out = [];
		foreach (preg_split('/[\r\n,;]+/', $raw) ?: [] as $d) {
			$d = rtrim(trim($d), '.');
			if ($d === '') {
				continue;
			}
			// 只接受合法域名，防路径穿越
			if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9\-_\.]*[A-Za-z0-9])?$/', $d) !== 1) {
				continue;
			}
			$out[$d] = true;
		}
		return array_keys($out);
	}

	/**
	 * 清理白名单域名对应的 Retry-After 文件
	 */
	public static function clearWhitelistedRetryAfter(): void {
		try {
			$dir = self::retryDir();
			if ($dir === null || !is_dir($dir)) {
				return;
			}
			foreach (self::getWhitelist() as $d) {
				$file = $dir . '/' . $d . '.txt';
				if (is_file($file)) {
					@unlink($file);
				}
			}
		} catch (Throwable $e) {
			// 任何异常都不能影响正常抓取
		}
	}

	/**
	 * 定位 Retry-After 目录
	 */
	private static function retryDir(): ?string {
		if (!defined('DATA_PATH')) {
			return null;
		}
		return rtrim(DATA_PATH, '/') . self::RETRY_DIR_REL;
	}

	/**
	 * 保存设置（configure.phtml 表单提交时调用）
	 */
	#[\Override]
	public function handleConfigureAction(): void {
		// 注意：不能调 $this->migrateExtensionUserPath()，它是 private，子类无权访问。
		// 且 extensionController 在显示配置页（GET）时也会调用本方法，
		// 这里抛异常会导致配置页整页空白。

		if (Minz_Request::isPost()) {
			$raw = Minz_Request::paramString(self::CONF_KEY);
			$raw = trim($raw);

			// 只保留合法域名，避免脏数据
			$clean = [];
			foreach (preg_split('/[\r\n,;]+/', $raw) ?: [] as $d) {
				$d = rtrim(trim($d), '.');
				if ($d === '') {
					continue;
				}
				if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9\-_\.]*[A-Za-z0-9])?$/', $d) === 1) {
					$clean[$d] = true;
				}
			}
			$value = implode("\n", array_keys($clean));

			FreshRSS_Context::userConf()->_attribute(self::CONF_KEY, $value);
			FreshRSS_Context::userConf()->save();

			// 保存后立刻生效一次
			self::clearWhitelistedRetryAfter();
		}
	}
}
