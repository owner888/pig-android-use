# Changelog

What changed, newest first, in pig's format: `## [x.y.z] - YYYY-MM-DD`, then only the sections that
have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`). Notes under
`## Unreleased` belong to no version yet; the version itself lives in the git tag.

## Unreleased

### Added

- Added a note on Pokémon TCG Pocket (`apps/pokemon-tcgp/APP.md`), this package's own, written from runs on a real phone: the game is one canvas, so read it from `android_tree`'s OCR and screenshot; its title screen, home, icon-only tab bar and battle page; packs, Wonder Pick, missions and solo battles. It takes the place of the `pig-skill-pokemon-tcgp` package, whose pixel coordinates assumed a 540-pixel-wide screen.

### Changed

- The skill sends the model to an app's shortcuts before its menus: share text or a file with `ACTION_SEND` into the app's own picker, open a page with its link (`ACTION_VIEW`), find things with the app's search box — none of which depends on how the app looks this version. `android_launch_app(screen="background")` returns the background screen's `display` id for `am start --display`.

- `android_tree` gives every node a `tap: [x, y]`, its centre, to pass to `android_tap` as it is. With only `rect: [x, y, w, h]` a model skipped the arithmetic and guessed — on Telegram it divided the tab bar into equal parts, opened the attachment dialog and backed out of the chat it wanted. The skill now says coordinates come from `tap` or the screenshot, never from dividing the screen.

### Fixed

- Fixed tree rects and screenshots disagreeing on a game: the screen's size was taken from the extent of the tree's nodes, and a game's window can stop short of the bottom, so the tree said 747×1568 where the screenshot of the same screen was 706×1568 and taps went off. The helper now sends the display's own logical size (rotation included) with the tree. Protocol version 6.
- Fixed a game's loading screen counting as an `adequate` tree: a Unity SurfaceView with a description was named, so the tree was only uncertain, and OCR finding little text settled it as adequate. A drawing surface (SurfaceView, TextureView) or a textless area covering half the screen now makes the tree `unusable` (`canvas`), with the screenshot.

- Fixed the model guessing where a game's icons are: an `unusable` tree came with the screenshot only when OCR found no text at all, so a game screen with a few words on it gave those words and nothing about its icon-only tab bar, and the model divided the screen width by the number of tabs. An `unusable` tree now always comes with the screenshot beside its `ocr_lines`; a `partial` one, which has most of the screen already, still gets the missing text only.

- Fixed the background screen going dark when the phone locked: on systems that put it in the main screen's display group whatever the flags say (Android 17 on Xiaomi), it slept with the phone, and screenshots were black. Before each action on it pig now wakes it if it is off — the phone itself in that case, to its lock screen, not unlocked — and the helper reports a user activity to its group, so it stays on while pig works and sleeps on its own timer once pig stops. The helper's keep-awake reads the display group on every tick, since the group is assigned after the display is made; `ping` reports each screen's group and state.
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
