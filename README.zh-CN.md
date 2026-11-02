# pig-android-use

给 pig 编码助手用的 Android 操作扩展：纯 PHP 通过 ADB 操作真机或模拟器——点击、滑动、中文输入、控件树、截图——并附带 23 个常用 App 的界面说明。

[English](README.md)

## 安装

```bash
pig install git:github.com/owner888/pig-android-use
```

跑 pig 的机器上还需要 `adb`（Android platform-tools），手机打开 USB 调试；或者打开无线调试，再 `/android connect <ip:port>`。除此之外什么都不用装：这个包没有 Composer 依赖。

在这个包出现之前，Android 操作是随 pig 内置的。`pig update` 会把 `~/.pig/agent/extensions/` 里旧的内置副本删掉，并打印上面那行安装命令；`disabledExtensions` 里写的 `pig-android-use` 照样管用。

## 工具

| 工具 | 做什么 |
| --- | --- |
| `android_ready` | 检查设备、点亮屏幕，返回前台 App、视口大小和一张截图。先调它。 |
| `android_observe` | 截图和/或前台 App |
| `android_tree` | 精简的控件树节点，由手机端小助手读取（见下文；需 Android 12+）（`type`、`text`、`desc`、`id`、`rect`）——原生 App 里的精确目标。会说明控件树够不够用（`tree_quality`）；不够用时补上树里没有的屏幕文字（OCR，macOS）或截图 |
| `android_tap` | 按截图坐标点击；自动换算成设备像素，带 ±2 像素抖动 |
| `android_swipe` | 按方向滑动，或在两点之间拖动 |
| `android_type_text` | 往获得焦点的输入框里打字——中文、符号、多行都行（走 Android 剪贴板，不用装输入法 App） |
| `android_press_button` | 返回、主屏、最近任务、电源、音量、回车 |
| `android_launch_app` | 按包名启动 App |
| `android_batch` | 一次调用最多 15 步，步与步之间停 150–350 毫秒 |
| `android_connect` | 通过 Wi-Fi 连接设备 |
| `android_doctor` | adb、ffmpeg、设备、屏幕尺寸、前台 App、亮屏状态 |

每个工具都可以带 `serial`，用来在多台设备里指定一台；操作屏幕的工具还可以带 `screen`——`main` 或 `background`（见下文）。操作类工具可以在同一次调用里返回结果截图（`observe: "screenshot"`），不用为了看一眼再单独调一次。

### 命令

`/android` 列出设备；还有 `/android connect <ip:port>`、`/android disconnect`、`/android screenshot`。

### Web 界面

用 `pig web` 时，`/api/android/stream` 是手机屏幕的实时画面。

### Skill

`android-use` 这个 skill 告诉模型什么时候用这些工具：**先用 bash 里的 adb**（`am start`、`input`、`settings`、`install`/`push`/`pull`），只有用户要求或 adb 做不到时才去点屏幕。它还讲了坐标、控件树、批量操作，以及什么时候停下来把手机交还给用户（密码、付款、刷脸或指纹）。

### App 知识

`skills/android-use/apps/` 里每个 App 一个 `APP.md`——Android 设置、百家号、哔哩哔哩、BOSS 直聘、Chrome、得到、抖音、飞书、Gmail、即刻、快手、LinkedIn、QQ 邮箱、Telegram、今日头条、Uber、Uber Eats、微信、微博、WhatsApp、闲鱼、小红书、知乎。每份说明这个 App 有哪些界面、功能在哪、哪里容易出错；skill 让模型在进入某个 App 之前先读对应的那份。标了 `pace: careful` 的 App 会盯自动化操作，模型在里面会放慢。

这些说明来自 [Mika](https://github.com/wangzan101/Mika)（Apache-2.0），原样收录，保持 Mika 写的中文——见 [NOTICE](NOTICE)。

### 其他 skill

另外三个来自 Mika 的 skill，中文：`messaging`（读聊天和邮件、拟稿、发给对的人并确认已发出）、`info-research`（追到原始出处、多方核对、写明出处和时间）和 `content-transcribe`（把文章、视频、播客整理成摘要、章节和引文）。`info-research` 和 `content-transcribe` 里原来的 Mika 工具名换成了 pig 的（`web_search`、`fetch_web_page`、`read`）。

## 配置

| 变量 | 含义 |
| --- | --- |
| `ADB_PATH` | `adb` 的路径，用于它不在 `PATH` 里、也不在 Android SDK 常见位置的情况 |
| `FFMPEG_PATH` | `ffmpeg` 的路径。有 ffmpeg 时，截图走手机的硬件 H.264 编码器（2K 屏上约 0.7 秒，不用 ffmpeg 要 2.3 秒）；没有就用 `screencap` |

### 后台副屏

`screen: "background"` 操作的是 pig 在手机上自己的一块屏，用户看不到：用 `android_launch_app(screen="background")` 在上面打开的 App 照常运行，用户可以同时用手机。这块屏是 pig 在手机上运行的一个小助手建的虚拟屏（`helper/`，Java，不依赖任何库）：pig 把 `helper/pig-android-helper.dex` 推到 `/data/local/tmp`，以 shell 身份用 `app_process` 启动，通过 `adb forward` 和它通信，请求要带只有 pig 知道的令牌。小助手负责持有这块屏和截图，还负责读控件树——这块屏的和主屏的都由它读，比 `uiautomator dump` 快（后者每读一次都要在手机上写一个文件）。它通过 UiAutomation 读取，每次读时连上、读完断开，这样别的程序照常可以用 `uiautomator dump`，也不会屏蔽用户手机上已开启的无障碍服务；点击、按键、启动 App 用 adb 发过去（`input -d`、`am start --display`）。

需要 Android 12 及以上。手机锁屏时也能用：pig 每次操作这块屏之前，如果它灭了就先唤醒，并重置它的息屏计时，所以 pig 干活期间它一直亮着，pig 停下后它按自己的计时息屏。拥有独立显示组的副屏只唤醒它自己。有些系统不管标志位怎么设，都把它放进主屏所在的显示组（小米的 Android 17 就是这样），这时唤醒的是手机本身——只亮出锁屏界面，不会解锁；副屏照常显示（`ALWAYS_UNLOCKED`）。`/android background` 查看状态，`/android background off` 关掉它和上面的 App；小助手 15 分钟没有请求会自己退出。

编译好的 .dex 已经提交在仓库里，使用时不用编译。改了 `helper/src` 之后用 Android SDK 重新编译：

```bash
ANDROID_HOME=~/Library/Android/sdk helper/build.sh
```

### 自绘界面的 App

游戏、画布、地图这类 App 的控件树里几乎没有带名字的节点，所以 `android_tree` 返回之前会先判断控件树够不够用：带名字的元素够不够多、控件大多有没有标签、有没有大块区域一个字都没有。拿不准时就读屏幕上的文字，把控件树里没有的那些行连同位置一起返回。OCR 用的是 Apple 的 Vision 框架，通过 `osascript` 调用（`resources/vision-ocr.js`）：支持中英文，什么都不用装，**仅限 macOS**。Linux 和 Termux 上没有 OCR，控件树不够用时改为附上截图。

截图会缩小（短边最多 768 像素），存在扩展自己的 artifacts 目录里；无痕会话的截图随会话一起删除。macOS 上用 `sips` 转换，其他系统用 PHP 的 GD；两者都没有时，原尺寸 PNG 原样发出。

## 开发

测试需要一个 pig 源码目录：

```bash
PIG_DIR=~/path/to/pig ~/path/to/pig/vendor/bin/phpunit -c phpunit.xml
```

`PIG_DIR` 默认是本包旁边的 `pig` 目录。

## 许可证

Apache-2.0——见 [LICENSE](LICENSE) 和 [NOTICE](NOTICE)。
