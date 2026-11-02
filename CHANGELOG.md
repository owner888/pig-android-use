# Changelog

What changed, newest first, in pig's format: `## [x.y.z] - YYYY-MM-DD`, then only the sections that
have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`). Notes under
`## Unreleased` belong to no version yet; the version itself lives in the git tag.

## Unreleased

### Changed

- Tool results are one line of JSON, with text as itself rather than `\uXXXX`: a 34-node tree with Chinese in it went from 12.7 KB on 556 lines to 4.6 KB, on every read.
- `android_tree` says when it left nodes out: past `max_nodes` it adds `truncated` with how many useful nodes the screen has, instead of a list the model took for the whole screen.
- An empty background screen says why — nothing opened on it yet, or the one before closed after 15 minutes unused (or the helper restarted) and took the app with it — and to open the app again, instead of the helper's "nothing has been drawn".

## [0.2.0] - 2026-11-02

### New Features

- The background screen — `screen: "background"` on `android_observe`, `android_tap`, `android_swipe`, `android_type_text`, `android_press_button`, `android_launch_app`, `android_tree` and `android_batch` works on a virtual display the person does not see, at the phone's own size and density, so the phone stays theirs while the model works. A helper of the package's own (`helper/`, Java, no libraries, built to a committed .dex) runs on the phone through `app_process` as the shell user, makes the display, holds its picture and takes its screenshots; pig reaches it through `adb forward` with a token only it knows, and re-forwards when adb drops the forward. Input and launches go through adb (`input -d`, `am start --display`). It works while the phone is locked: before each action pig wakes the screen if it is off and the helper restarts its group's sleep timer, so it stays on while pig works and sleeps on its own afterwards — on systems that keep it in the main screen's display group (Android 17 on Xiaomi) that lights the lock screen, not unlocked. Android 12+. `/android background [off]`; the helper exits after 15 minutes unused.
- A faster view tree — `android_tree` reads the tree through the helper on both screens (UiAutomation, connected for each read so `uiautomator dump` keeps working, without suppressing the person's accessibility services): about 0.4 s a read against 2.5 s for `uiautomator dump`. The helper also sends the screen's own size (rotation included), so tree rects and screenshots share one coordinate space. Needs Android 12+.
- Apps that draw their own UI — `android_tree` says whether the tree is enough to work from (`tree_quality`: `adequate`, `partial`, `unusable`, `unchecked`, with a `tree_quality_reason`), by rules that follow Mika's `TreeQuality.kt` plus one of its own: a drawing surface or textless area over half the screen is a `canvas`. When the tree is not clearly enough, on macOS it reads the screen with Apple's Vision framework (`osascript -l JavaScript`, nothing to install) and returns the lines the tree lacks as `ocr_lines`; an `unusable` tree always comes with the screenshot too, for icons OCR cannot read. Elsewhere a partial or unusable tree comes with the screenshot.
- The model learns the apps it works in — the skill has it keep a notes file per app, `~/.pig/agent/android-use/notes/<package>.md`, read before working in the app and, after each run, added to or corrected with what it did and then saw: dated, with the app's version, positions in words rather than pixels, the path that worked for a task. A failed task or a lost game gets a lesson — what decided it and what to try next — which stays "to try" until it has helped twice and is dropped when it has not, with a win/loss tally for choices such as a deck against an opponent. The notes win over `APP.md` where they disagree, and live outside the package, so updates leave them alone.

### Added

- Added `tap: [x, y]` to every `android_tree` node — its centre, to pass to `android_tap` as it is — and `viewport`, the size its coordinates are in.
- Added a note on Pokémon TCG Pocket (`apps/pokemon-tcgp/APP.md`), this package's own, written from runs on a real phone: how to read its canvas, its screens and icon-only tab bar, packs, Wonder Pick, missions, solo battles and what to record after one. It takes the place of the `pig-skill-pokemon-tcgp` package.
- Added three skills from Mika: `messaging`, `info-research` and `content-transcribe` (with its output-format reference). `messaging` is unmodified; the other two name pig's tools (`web_search`, `fetch_web_page`, `browse_web_page`, `read`) where Mika's named its own, and say so at the top.
- `android_launch_app(screen="background")` returns the background screen's `display` id, for `am start --display`.

### Changed

- The skill takes an app's shortcuts before its menus — share text or a file with `ACTION_SEND` into the app's own picker, open a page with its link (`ACTION_VIEW`), find things with the app's search box — and says coordinates come from a node's `tap` or the screenshot, never from dividing the screen.
- A tree that could not be read, or came back empty, is an `unusable` tree and gets OCR or the screenshot, instead of an empty answer.

### Fixed

- Fixed taps landing off target when a screenshot came between reading the tree and tapping: rects were scaled by whatever the last screenshot had set, 1:1 before any. Each screen now keeps its own ratio, set by its screenshots and its tree reads alike. The web UI's live screen no longer changes the model's coordinates.

## [0.1.0] - 2026-11-02

### New Features

- Android use as its own package — The extension that was bundled with pig as `extensions/pig-android-use` is now this package, installed with `pig install git:github.com/owner888/pig-android-use` and licensed Apache-2.0. Tools, command, skill and web page are the ones pig shipped; the classes moved from `Pig\Extensions\AndroidUse\` to `PigAndroidUse\`, and the extension is still named `pig-android-use`, so `disabledExtensions` keeps working.
- App knowledge — `skills/android-use/apps/` holds notes on 23 apps (WeChat, Xiaohongshu, Douyin, Bilibili, BOSS Zhipin, Feishu, Telegram, …) from [Mika](https://github.com/wangzan101/Mika), unmodified. The skill has the model read the matching `APP.md` before it works in an app, and slow down in those marked `pace: careful`.
