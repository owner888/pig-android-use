---
name: android-use
description: Drive a real or emulated Android phone over ADB, in pure PHP. Last resort after the command line — when adb from bash can do it (start an activity, change a setting, push/pull/install), do that; use these screen tools only when the person asks for them or adb cannot do the job. Start a new task with android_ready to get the device channel and the current screen; supports taps, swipes, Chinese text input, launching apps by package and a screenshot when the view needs checking; has notes on common apps (WeChat, Xiaohongshu, Douyin, …).
---

# Doing a task on an Android phone

The native `android_*` tools drive an Android phone or tablet.

**Try adb first.** Tapping the screen is a last resort. What `adb` can do from `bash` stays off the screen: open an app or a page with `adb shell am start`, type or press keys with `adb shell input`, change settings with `adb shell settings`, install or copy files with `adb install` / `adb push` / `adb pull`, read state with `adb shell dumpsys`. Use `android_*` only when the person asks for it, or when the task needs eyes on the screen (an app's own UI with no adb route).

**Inside an app, take its shortcuts before its menus.** Finding your way through an app's screens is slow and goes wrong; these do not depend on how the app looks:

- **Sending text or a file into an app**: share it. `adb shell am start -a android.intent.action.SEND -t text/plain --es android.intent.extra.TEXT '<text>' -p <package>` opens the app's own "send to" picker; pick the chat there and send. For a file, `-t <mime> --eu android.intent.extra.STREAM <content uri>`.
- **Opening a page, a person or a channel**: try the app's link, `adb shell am start -a android.intent.action.VIEW -d '<link>'` (`https://…`, or the app's own scheme such as `tg://resolve?domain=<name>`).
- **Finding something in an app**: use its own search box rather than scrolling through lists or tabs.
- On the background screen, add `--display <id>` to `am start`; `android_launch_app(screen="background")` gives the id as `display`.

Settle what the person wants delivered and when it is done. Treat an action that returned normally as done and move on; do not spend a separate call looking at the screen to prove each tap, swipe or keystroke worked. When the next step needs to see the screen, set `observe="screenshot"` on the action itself.

## App knowledge

Before working inside an app, look for its notes in `apps/` next to this file: one folder per app with an `APP.md` (`apps/wechat/APP.md`, `apps/xiaohongshu/APP.md`, …). Match the app by the `packages` or `aliases` in each file's frontmatter, then read that file — it describes the app's screens, where its features live and the places that are easy to get wrong. Covered: Android Settings, Baijiahao, Bilibili, BOSS Zhipin, Chrome, Dedao, Douyin, Feishu, Gmail, Jike, Kuaishou, LinkedIn, QQ Mail, Telegram, Toutiao, Uber, Uber Eats, WeChat, Weibo, WhatsApp, Xianyu, Xiaohongshu, Zhihu, and the game Pokémon TCG Pocket.

- The notes are in Chinese. Most were written for Mika (another Android agent), so they describe the apps, not these tools; act on them with the `android_*` tools and adb as usual. A note's screens may be out of date for the version on the phone: what the tree and the screenshot show wins.
- `open` is the app's name on the launcher; `packages` is what `android_launch_app` and `adb shell am start` take.
- `pace: careful` marks an app that watches for automation: fewer screens per minute, `android_batch` rather than a burst of single taps, no rapid repeats, and stop as soon as the app says you are going too fast or asks for verification.
- An app with no folder here has no notes; use the view tree and the screen as usual.

## What you learn about an app

Keep your own notes on the apps you work in, one file per app: `~/.pig/agent/android-use/notes/<package>.md` (pig's agent directory — `$PIG_CODING_AGENT_DIR` when that is set). They are yours and the person's, outside this package, so an update never touches them.

**Before** working in an app, read its notes file if there is one, after its `APP.md`. Where they disagree, the notes win: they were seen on this phone, at this version.

**After** the task — done or not — add what this run showed that the notes lack, and correct what it showed to be wrong:

- Only what you did and then saw on the screen: "tapping the pack on the home screen opens 選擇擴充包, a list of packs", "a 公告 dialog covers the home screen after a cold start; its ✕ is top right". Not guesses, not what you expected to see.
- Positions in words, never pixels: "4th icon from the left in the tab bar", "top right of the dialog", "the second row under the search box". Pixels hold for one screen size; words hold for all.
- The path that worked, step by step, for a task worth doing again ("open a free pack: …"), and what went wrong on the way and how it was got past.
- Each entry starts with the date and the app's version (`adb shell dumpsys package <package> | grep versionName`). When an entry turns out wrong, change that entry — do not add a second one that contradicts it.
- Keep the file short: one line per fact, a heading per screen or task. Create the directory if it is missing.

**When a task failed or a game was lost, learn from it** — under a `## Lessons` heading in the same file:

- What happened: the result (lost, at which step it failed), and for a game the opponent, your deck and how many turns.
- What decided it: the one decision or step that cost the most, as seen on the screen ("attacked with 30 damage while the opponent's 90-HP ex was one energy from its 120 attack"), not a general feeling.
- What to do next time, marked **to try**. A lesson from one game is a guess: it is not a rule yet.
- When you try it, add the outcome to that entry. Two outcomes where it helped make it a **rule**; one where it did not, and it goes.
- Keep a tally for what you can choose (a deck against an opponent, one way of doing a task against another): `deck A vs B: 3W 2L`. Choose by the tally, not by the last game.
- Before the next task or game in that app, read the rules and the to-try entries, and act on them.

## 1. Ready check

Call this once before anything else:
```json
android_ready()
```
- Returns `ready=true` with the foreground app's package, the viewport size, the `pixel_to_point` ratio and a real screenshot.
- If no device is connected, ask the person to plug in a USB cable and turn on Developer options → USB debugging, or pair over wireless debugging.
- A screen that is off is woken by `android_ready` itself.

## 2. Tapping by coordinates

**Coordinates come from a node's `tap`, or from where the thing is in the screenshot — never from arithmetic on the screen size.** Do not divide the width by the number of tabs or guess a bar's height: tab bars are not evenly spaced, and a guess that lands on the wrong control can open a dialog or leave the page. If a target is not in the tree and not clearly visible, look again (`android_tree`, or `android_observe`) before tapping.

- Screenshots come from ADB and are scaled down (short side at most 768px), so each one is cheap.
- **Coordinates**: pass the pixel `(px, py)` you see in the screenshot straight to `android_tap(x=px, y=py)`; the tool converts it back to the device's resolution with the returned `pixel_to_point` ratio.
- **Look in the same call**: if a tap changes the page and the next step needs to see it, set `observe="screenshot"` so the tap and the new screenshot come back together.

## 3. The view tree

- **Exact targets in native apps**: when you need the exact text in a native app (Settings, WeChat, shopping apps), a list of search results, or the screenshot is not clear enough, call `android_tree(max_nodes=60)`.
- It returns the useful nodes with `type`, `text`, `desc`, `id`, `tap: [x, y]` — the point to tap, its centre — and `rect: [x, y, w, h]`, in the same coordinates as that screen's screenshots (`viewport` says their size). To tap a node, pass its `tap` as it is: `android_tap(x=tap[0], y=tap[1])`.
- It also says whether the tree is enough to work from: `tree_quality` is `adequate`, `partial` (some of the screen is not in it) or `unusable` (an app that draws its own UI: a game, a canvas, a map). When it is not adequate, on macOS the result has `ocr_lines` — the text on the screen the tree lacks, each with a `rect` in the same coordinates — and an `unusable` tree also comes with the screenshot, since icons without text (a game's buttons and tab bar) are only in the picture: take their positions from it, never by dividing the screen. Elsewhere there is no OCR, and a partial or unusable tree comes with the screenshot instead; `unchecked` means the tree could not be checked against the screen.

## 4. Batches

- **Known sequences**: for a fixed run of steps (tap the search box → type → confirm):
  `android_batch(steps=[{"op": "tap", "args": {"x": 100, "y": 200}}, {"op": "type_text", "args": {"text": "search text"}}], observe="screenshot")`
- Each tap gets ±2px of jitter and 150–350ms random pauses are inserted between steps.

## 5. Swiping and scrolling

- **By direction**: to move through a long list, `android_swipe(direction="up")` (swipes up to show what is below).
- **Exact drags**: to drag a card, icon or slider to a place, give both ends: `android_swipe(from_x=..., from_y=..., to_x=..., to_y=..., duration_ms=500)`.

## 6. Typing text

- Tap the field first, then `android_type_text(text="what to type")`.
- Chinese, English, emoji and multiple lines all work, on either screen. The result says whether the field, read back, holds the text: `verified: false` (or `method: "paste"`) means look with `android_tree` before going on — never assume it went in.
- Do not work around typing with intent extras or the clipboard yourself: if typing fails, say so.

## 7. Buttons and launching apps

- **Navigation buttons**:
  - Back: `android_press_button(name="back")`
  - Home: `android_press_button(name="home")`
  - Recent apps: `android_press_button(name="app_switch")`
- **Launch an app** by package: `android_launch_app(package="com.tencent.mm")`.

## 8. The background screen

pig has a screen of its own on the phone that the person does not see: an app opened there runs while they keep using the phone. Use it when the work is long or the person is using the phone; the main screen when they want to watch, or the task needs what only the main screen has (the view tree, the lock screen, notifications).

- Open the app there: `android_launch_app(package="com.tencent.mm", screen="background")`. Then pass `screen="background"` to every call for that work — `android_observe`, `android_tap`, `android_swipe`, `android_type_text`, `android_press_button`, `android_batch`, `android_tree`. A call without it goes to the main screen, in front of the person.
- Coordinates come from that screen's own screenshot, as on the main screen.
- `android_tree(screen="background")` reads its view tree as on the main screen.
- `home` has no launcher there; open another app with `android_launch_app` instead.
- One app runs in one place: an app open on the background screen is not also on the main screen. If the person opens it themselves, it moves to them; open it again in the background when they are done.
- It works while the phone is locked: each call on it wakes the screen if it is off and keeps it from sleeping while you work. On some phones that lights up the person's lock screen (not unlocked) for the time you work; it goes dark on its own timer once you stop.
- It needs Android 12 or newer — as does `android_tree` on either screen, which reads through the same helper. `/android background off` closes it and the app on it; it also closes after 15 minutes without use.

## 9. When to stop and hand over

- **Something in the way**: a coupon, promotion or permission dialog over the screen — find its "X" or "Close/Cancel" and dismiss it, then carry on.
- **Passwords and biometrics**: at a lock-screen password, a WeChat/Alipay payment password, or a face/fingerprint check, stop and tell the person to finish it on the phone themselves; continue once they have.

## 10. Fast decision loop with decision models (System 1 / Jev Fast-Loop)

To eliminate the multi-second round-trip delay of streaming screenshots back to the main LLM for every step, use layered execution:

1. **CLI First**: Whenever `adb shell` (`am`, `pm`, `input`, `settings`) can accomplish the goal, never touch the screen.
2. **Fast Decision Loop**: Inside `codemode`, run a self-contained loop: fetch `android_tree` → pass interactive candidate nodes to a fast decision model (`$models->classify()`) → execute `android_tap`. No intermediate screenshots are sent to the primary model, completing each step in hundreds of milliseconds.
3. **Multi-Provider Failover**: Use `$models->getAvailableOfType('classifier')` to discover configured providers dynamically. If any provider returns 401 or hits quota limits, cascade seamlessly to the next available decision model (e.g. TypeSafe Jev → OpenRouter → Cloudflare).
4. **Vision Fallback**: Only fallback to `android_observe(mode="screenshot")` when the view tree yields no interactive candidates, a custom Canvas surface is present, or automated decisions repeatedly fail.

```php
// Dynamic multi-provider classifier fallback in codemode
function decideNode($models, $context) {
    $classifiers = $models->getAvailableOfType('classifier');
    foreach ($classifiers as $c) {
        $res = $models->classify($c, $context);
        if (($res['stopReason'] ?? '') === 'stop') {
            return $res['answers'];
        }
    }
    return null;
}

$tree = json_decode($tools->android_tree(['max_nodes' => 30]), true);
$criteria = [];
$nodes = [];
foreach ($tree['nodes'] as $i => $n) {
    $label = trim(($n['text'] ?? '') . ' ' . ($n['desc'] ?? '') . ' ' . ($n['id'] ?? ''));
    if ($label !== '' && isset($n['rect'])) {
        $key = 'node_' . $i;
        $criteria[$key] = $label;
        $nodes[$key] = $n;
    }
}

$answers = decideNode($models, [
    'state' => ['instruction' => 'Tap the install button'],
    'questions' => [
        'target' => [
            'type' => 'choice',
            'instructions' => 'Choose the interactive element that best satisfies the instruction',
            'criteria' => $criteria,
        ]
    ]
]);

$choice = $answers['target']['choice'] ?? null;
if ($choice && isset($nodes[$choice])) {
    [$x, $y, $w, $h] = $nodes[$choice]['rect'];
    $tools->android_tap(['x' => $x + $w / 2, 'y' => $y + $h / 2]);
}
```

