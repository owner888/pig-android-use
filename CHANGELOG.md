# Changelog

What changed, newest first, in pig's format: `## [x.y.z] - YYYY-MM-DD`, then only the sections that
have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`). Notes under
`## Unreleased` belong to no version yet; the version itself lives in the git tag.

## [0.1.0] - 2026-11-02

### New Features

- Android use as its own package — The extension that was bundled with pig as `extensions/pig-android-use` is now this package, installed with `pig install git:github.com/owner888/pig-android-use` and licensed Apache-2.0. Tools, command, skill and web page are the ones pig shipped; the classes moved from `Pig\Extensions\AndroidUse\` to `PigAndroidUse\`, and the extension is still named `pig-android-use`, so `disabledExtensions` keeps working.
- App knowledge — `skills/android-use/apps/` holds notes on 23 apps (WeChat, Xiaohongshu, Douyin, Bilibili, BOSS Zhipin, Feishu, Telegram, …) from [Mika](https://github.com/wangzan101/Mika), unmodified. The skill has the model read the matching `APP.md` before it works in an app, and slow down in those marked `pace: careful`.
