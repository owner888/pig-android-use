# Changelog

What changed, newest first, in pig's format: `## [x.y.z] - YYYY-MM-DD`, then only the sections that
have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`). Notes under
`## Unreleased` belong to no version yet; the version itself lives in the git tag.

## Unreleased

### Fixed

- Fixed taps landing off target after reading the tree: rects were scaled by whatever the last screenshot had set — 1:1 before any screenshot, so a screenshot taken between reading the tree and tapping moved every target. Each screen now keeps its own ratio, and `android_tree` sets it from the screen size in the tree itself (rotation included), the same one a screenshot of that screen gets; the result says which `viewport` its rects are in. The web UI's live screen no longer changes the model's coordinates.

### New Features

- The background screen — `screen: "background"` on `android_observe`, `android_tap`, `android_swipe`, `android_type_text`, `android_press_button`, `android_launch_app`, `android_tree` and `android_batch` works on a virtual display the person does not see, so the phone stays theirs while the model works. A helper of the package's own (`helper/`, Java, built to a committed .dex) runs on the phone through `app_process` as the shell user, makes the display and takes its screenshots, reached through `adb forward` with a token; input and launches go through adb with `-d` / `--display`. `android_tree` reads the view tree through the helper on both screens (UiAutomation, connected per read, not suppressing the person's accessibility services), in uiautomator's XML — on the main screen too, in place of `uiautomator dump`, which wrote and read back a file on the phone for every call. It now needs Android 12+ there as well. Android 12+. `/android background [off]`; the helper exits after 15 minutes unused.

- Apps that draw their own UI — `android_tree` judges whether the tree is enough to work from (`tree_quality`: `adequate`, `partial`, `unusable`, `unchecked`, with a `tree_quality_reason`), by rules that follow Mika's `TreeQuality.kt`. When it is not clearly enough, on macOS it reads the screen with Apple's Vision framework (`osascript -l JavaScript`, nothing to install) and returns the lines the tree lacks as `ocr_lines`, with rects in the screenshot's coordinates; elsewhere a partial or unusable tree comes with the screenshot. A failed dump is now an unusable tree that gets the same treatment, instead of an empty answer.

### Added

- Added three skills from Mika: `messaging`, `info-research` and `content-transcribe` (with its output-format reference). `messaging` is unmodified; the other two name pig's tools (`web_search`, `fetch_web_page`, `browse_web_page`, `read`) where Mika's named its own, and say so at the top.

## [0.1.0] - 2026-11-02

### New Features

- Android use as its own package — The extension that was bundled with pig as `extensions/pig-android-use` is now this package, installed with `pig install git:github.com/owner888/pig-android-use` and licensed Apache-2.0. Tools, command, skill and web page are the ones pig shipped; the classes moved from `Pig\Extensions\AndroidUse\` to `PigAndroidUse\`, and the extension is still named `pig-android-use`, so `disabledExtensions` keeps working.
- App knowledge — `skills/android-use/apps/` holds notes on 23 apps (WeChat, Xiaohongshu, Douyin, Bilibili, BOSS Zhipin, Feishu, Telegram, …) from [Mika](https://github.com/wangzan101/Mika), unmodified. The skill has the model read the matching `APP.md` before it works in an app, and slow down in those marked `pace: careful`.
