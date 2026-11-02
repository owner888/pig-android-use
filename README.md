# pig-android-use

Android use for the pig coding agent: drive a real or emulated Android phone over ADB, in pure PHP — taps, swipes, Chinese text input, the view tree and screenshots — with notes on how 23 common apps are laid out.

[中文](README.zh-CN.md)

## Installation

```bash
pig install git:github.com/owner888/pig-android-use
```

You also need `adb` (Android platform-tools) on the machine pig runs on, and a phone with USB debugging on — or wireless debugging, then `/android connect <ip:port>`. Nothing else: the package has no Composer dependencies.

Before this package existed, Android use was bundled with pig. `pig update` removes the old bundled copy from `~/.pig/agent/extensions/` and prints the line above; settings that name `pig-android-use` in `disabledExtensions` still reach it.

## Tools

| Tool | What it does |
| --- | --- |
| `android_ready` | Checks for a device, wakes the screen, returns the foreground app, the viewport and a screenshot. Call it first. |
| `android_observe` | A screenshot and/or the foreground app |
| `android_tree` | The view hierarchy as compact nodes (`type`, `text`, `desc`, `id`, `rect`) — exact targets in native apps. Says whether the tree is enough (`tree_quality`); when it is not, adds the screen text it lacks (OCR, macOS) or the screenshot |
| `android_tap` | Tap at screenshot coordinates; converted to device pixels, ±2 px jitter |
| `android_swipe` | Swipe by direction, or drag between two points |
| `android_type_text` | Type into the focused field — Chinese, symbols, several lines (through the Android clipboard, no keyboard app needed) |
| `android_press_button` | back, home, recent apps, power, volume, enter |
| `android_launch_app` | Launch an app by package name |
| `android_batch` | Up to 15 steps in one call, with 150–350 ms pauses between them |
| `android_connect` | Connect to a device over Wi-Fi |
| `android_doctor` | adb, ffmpeg, devices, screen size, foreground app, screen state |

Every tool takes an optional `serial` for a machine with several devices, and the screen tools a `screen` — `main` or `background` (below). An action can return a screenshot of its result in the same call (`observe: "screenshot"`), so there is no separate call just to look.

### Command

`/android` shows the devices; `/android connect <ip:port>`, `/android disconnect`, `/android screenshot`.

### Web UI

With `pig web`, `/api/android/stream` is a live view of the phone's screen.

### Skill

The `android-use` skill tells the model when to use these tools: **adb from bash comes first** (`am start`, `input`, `settings`, `install`/`push`/`pull`), and the screen only when the person asks for it or adb cannot do the job. It also covers coordinates, the view tree, batches, and when to stop and hand the phone back (passwords, payment, face or fingerprint checks).

### App knowledge

`skills/android-use/apps/` holds one `APP.md` per app — Android Settings, Baijiahao, Bilibili, BOSS Zhipin, Chrome, Dedao, Douyin, Feishu, Gmail, Jike, Kuaishou, LinkedIn, QQ Mail, Telegram, Toutiao, Uber, Uber Eats, WeChat, Weibo, WhatsApp, Xianyu, Xiaohongshu, Zhihu. Each says what the app's screens are, where its features live and what is easy to get wrong; the skill has the model read the matching one before it works in that app. Apps marked `pace: careful` watch for automation, and the model slows down in them.

These notes come from [Mika](https://github.com/wangzan101/Mika) (Apache-2.0), unmodified and in Chinese, as Mika wrote them — see [NOTICE](NOTICE).

### More skills

Three more skills from Mika, in Chinese: `messaging` (read chats and mail, draft and send to the right person, check it went out), `info-research` (find the original source, cross-check, cite source and date) and `content-transcribe` (summaries, chapters and quotes from articles, videos and podcasts). `info-research` and `content-transcribe` name pig's tools (`web_search`, `fetch_web_page`, `read`) where Mika's had its own.

## Configuration

| Variable | Meaning |
| --- | --- |
| `ADB_PATH` | The `adb` binary, when it is not on `PATH` or in the Android SDK's usual places |
| `FFMPEG_PATH` | The `ffmpeg` binary. With ffmpeg, screenshots come from the phone's hardware H.264 encoder (about 0.7 s instead of 2.3 s on a 2K screen); without it, from `screencap` |

### The background screen

`screen: "background"` works on pig's own screen on the phone, which the person does not see: an app opened there with `android_launch_app(screen="background")` runs while they keep using the phone. It is a virtual display made by a small helper pig runs on the phone (`helper/`, Java, no libraries): pig pushes `helper/pig-android-helper.dex` to `/data/local/tmp`, starts it with `app_process` as the shell user, and talks to it through `adb forward`, with a token only pig knows. The helper holds the screen and takes its screenshots; taps, keys and launches go to it with adb (`input -d`, `am start --display`). The view tree is read from the main screen only, so on the background screen `android_tree` gives the screen text (OCR) or the screenshot.

Android 12 or newer. Where the system keeps the screen in the main screen's display group (Android 17 does, `create` reports `group=0`), it sleeps when the phone does; pig does not keep it awake then, since that would keep the person's screen on too. `/android background` shows it, `/android background off` closes it and the app on it; the helper exits by itself after 15 minutes without a request.

The .dex is committed, so nothing needs building to use it. After changing `helper/src`, rebuild it with the Android SDK:

```bash
ANDROID_HOME=~/Library/Android/sdk helper/build.sh
```

### Apps that draw their own UI

A game, a canvas or a map has few named nodes or none, so `android_tree` judges the tree before returning it — enough named elements, most controls labelled, no large area without text. When it is not clearly enough, it reads the text on the screen and returns the lines the tree lacks, with their positions. The OCR is Apple's Vision framework, run through `osascript` (`resources/vision-ocr.js`): Chinese and English, nothing to install, **macOS only**. On Linux and Termux there is no OCR, and a tree that falls short comes with the screenshot instead.

Screenshots are scaled down (short side at most 768 px) and kept in the extension's artifacts directory; an incognito session's go with it. On macOS they are converted with `sips`, elsewhere with PHP's GD; with neither, the full-size PNG is sent as it is.

## Development

The tests run against a pig checkout:

```bash
PIG_DIR=~/path/to/pig ~/path/to/pig/vendor/bin/phpunit -c phpunit.xml
```

`PIG_DIR` defaults to a `pig` directory next to this package.

## License

Apache-2.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE).
