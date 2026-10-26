# xExtension-NoRetry

> 让指定域名的 RSS 订阅不再被 FreshRSS 的 Retry-After 域名退避机制连累。

[![FreshRSS](https://img.shields.io/badge/FreshRSS-1.20%2B-blue)](https://freshrss.org)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

## 解决的问题

FreshRSS 有个**域名级**的退避（Retry-After）机制：当某个订阅返回 `429` / `503` 或请求超时时，
它会在 `data/Retry-After/` 下创建一个以**域名**命名的空文件，文件的 mtime 就是退避到期时间。

问题是：**这个退避是按域名算的，不是按订阅算的。**

举例：

```
https://rsshub.example.com/weibo/user/123   ← 正常
https://rsshub.example.com/zhihu/xxx        ← 超时（知乎抓取慢）
```

只要知乎那条失败，整个 `rsshub.example.com` 下的**所有**订阅都会被冻结，
包括本来完全正常的微博订阅，直到退避到期。

这个扩展让你指定一批域名，这些域名永远不会被退避。

## 工作原理

退避检查在 `FreshRSS_http_Util::httpGet()` 内部执行，发生在所有扩展 hook **之前**，
所以扩展没办法在那一刻拦截。

但换个角度：**如果退避文件不存在，退避就不会生效。**

扩展注册 `freshrss_init` hook（每次已登录请求都会触发），在请求早期把白名单域名的
`Retry-After/*.txt` 删掉。文件不存在 → `getRetryAfter()` 返回 0 → 不触发退避。

效果等同于「白名单域名永不退避」，且**完全不修改 FreshRSS 源码**。

## 安装

### 方式一：Git clone（推荐）

仓库根目录就是插件本体，直接 clone 到 extensions 下即可：

```bash
cd /path/to/FreshRSS/extensions
git clone https://github.com/unliar/freshrss-noRetry-extension.git xExtension-NoRetry
```

> 注意末尾的 `xExtension-NoRetry` 是目标目录名。
> FreshRSS 只扫描 `extensions/` 的**下一级目录**，目录名建议保持 `xExtension-` 前缀，便于识别。

### 方式二：手动下载

1. 下载本仓库 zip，解压后把内容放到 `FreshRSS/extensions/xExtension-NoRetry/`
2. 该目录下应该直接看到 `metadata.json`、`extension.php`、`configure.phtml` 三个文件（不要再套一层）

### 启用

1. 登录 FreshRSS
2. 设置 → 扩展
3. 找到 **No Retry-After**，勾选启用
4. 点齿轮图标进入配置

## 配置

启用后进入扩展配置页，在文本框中填入域名，一行一个（也支持逗号、分号分隔）：

```
rsshub.flyneko.com
my-rsshub.example.com
```

点保存即可，保存时会自动清理一次退避文件。

## 验证是否生效

```bash
# 1. 手动造一个退避文件
docker exec freshrss touch -d "+1 hour" /var/www/FreshRSS/data/Retry-After/你的域名.txt

# 2. 浏览器里登录 FreshRSS，随便点一个页面

# 3. 检查文件是否被清掉
docker exec freshrss ls /var/www/FreshRSS/data/Retry-After/
```

文件消失 = 生效。

> ⚠️ 注意：`freshrss_init` hook **只在用户已登录时触发**
> （源码里 `FreshRSS_Auth::hasAccess() || allowAnonymous()` 判断）。
> 未登录访问不会加载扩展，所以文件保留是正常现象。

## 手动清退避的替代方案

如果不想装扩展，也可以直接删文件（一次性的）：

```bash
docker exec freshrss rm -f /var/www/FreshRSS/data/Retry-After/域名.txt
```

或者宿主机上（如果你挂载了 data 目录）：

```bash
rm -f /path/to/freshrss/data/Retry-After/域名.txt
```

## 兼容性

- 测试环境：FreshRSS **1.30.1**，PHP 8.x
- 理论支持 FreshRSS 1.20+（`freshrss_init` hook 从较早版本就有）
- 扩展目录是持久化卷的，**更新镜像不会丢失**

## 技术细节（给想改代码的人）

几个容易踩的坑，都在 1.30.1 上实测过：

1. **扩展标识符是 `metadata.json` 的 `name` 字段**
   （比如 `"No Retry-After"`），不是 `entrypoint`，也不是目录名。
   `extensions_enabled` 数组的 key 和 URL 的 `e` 参数都必须用这个完整名称。

2. **`getConfigureView()` 是 `final`**，不能覆写。
   它会自动 `include` 扩展目录下的 `configure.phtml`。

3. **`configure.phtml` 里 `$this` 不是 `FreshRSS_View`**
   写 `$this->extension->getName()` 会 Fatal（`$this->extension` 是 null）。
   而且这个文件被渲染在框架自己的 `<form>` 之外，必须**自己包 `<form>`** 并带 CSRF token。

4. **`handleConfigureAction()` 在显示配置页（GET）时也会被调用**，
   里面不能抛异常，否则配置页整页空白。也**不能调 `private` 的 `migrateExtensionUserPath()`**。

## License

MIT
