package pig.androiduse.helper;

import android.content.Context;
import android.net.LocalServerSocket;
import android.net.LocalSocket;
import android.os.Build;
import android.os.Looper;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.HashMap;
import java.util.Map;

/**
 * pig-android-use's helper on the phone: the part of Android use that `adb shell` cannot do by
 * itself. It creates pig's background screen (a virtual display), keeps its picture, hands out
 * screenshots of it, reads the view tree of any screen, puts text into the focused field of any screen — faster than `uiautomator dump`,
 * which writes a file on the phone for each read. Taps, keys and launching apps on that screen stay with adb
 * (`input -d`, `am start --display`), which reach any display already.
 *
 * Started by pig over adb:
 *
 *     CLASSPATH=/data/local/tmp/pig-android-helper.dex app_process / pig.androiduse.helper.Main <socket> <token>
 *
 * It listens on the abstract socket <socket> (pig reaches it with `adb forward`), one JSON request
 * per connection, one JSON line back. Every request carries <token>: any app on the phone can
 * connect to an abstract socket, only pig knows the token.
 *
 * It runs as the shell user and exits after IDLE_MS without a request, or on `quit`, releasing
 * its screens — an app left on one is closed with it.
 */
public final class Main {
    /** Bumped with every change to the protocol; pig restarts a helper whose version differs. */
    static final int VERSION = 7;

    private static final long IDLE_MS = 15 * 60 * 1000;

    // Display.DEFAULT_DISPLAY
    private static final int MAIN_DISPLAY = 0;

    private final String token;
    private final Context context;
    private final Map<Integer, AgentDisplay> displays = new HashMap<>();
    private volatile long lastRequest = System.currentTimeMillis();

    private Main(String token, Context context) {
        this.token = token;
        this.context = context;
    }

    public static void main(String[] args) throws Exception {
        if (args.length < 2) {
            System.err.println("usage: app_process / pig.androiduse.helper.Main <socket> <token>");
            System.exit(2);
        }

        Looper.prepareMainLooper();
        Main main = new Main(args[1], ShellContext.create());
        Runtime.getRuntime().addShutdownHook(new Thread(main::releaseAll));
        main.watchIdle();
        main.serve(args[0]);
    }

    private void serve(String socketName) throws Exception {
        LocalServerSocket server = new LocalServerSocket(socketName);
        System.out.println("pig-android-helper " + VERSION + " listening on @" + socketName);

        while (true) {
            try (LocalSocket client = server.accept()) {
                lastRequest = System.currentTimeMillis();
                BufferedReader in = new BufferedReader(new InputStreamReader(client.getInputStream(), StandardCharsets.UTF_8));
                String line = in.readLine();
                JSONObject reply;
                boolean quit = false;

                try {
                    JSONObject request = new JSONObject(line == null ? "{}" : line);
                    if (!MessageDigest.isEqual(token.getBytes(StandardCharsets.UTF_8), request.optString("token").getBytes(StandardCharsets.UTF_8))) {
                        reply = error("wrong token");
                    } else {
                        quit = "quit".equals(request.optString("cmd"));
                        reply = handle(request);
                    }
                } catch (Throwable t) {
                    Throwable cause = AgentDisplay.cause(t);
                    reply = error(cause.getClass().getSimpleName() + ": " + cause.getMessage());
                    cause.printStackTrace();
                }

                OutputStream out = client.getOutputStream();
                out.write((reply.toString() + "\n").getBytes(StandardCharsets.UTF_8));
                out.flush();

                if (quit) {
                    releaseAll();
                    System.exit(0);
                }
            }
        }
    }

    private JSONObject handle(JSONObject request) throws Exception {
        String cmd = request.optString("cmd");

        switch (cmd) {
            case "ping": {
                JSONArray ids = new JSONArray();
                JSONArray screens = new JSONArray();
                synchronized (displays) {
                    for (AgentDisplay display : displays.values()) {
                        ids.put(display.id);
                        screens.put(display.status());
                    }
                }
                return ok().put("version", VERSION).put("sdk", Build.VERSION.SDK_INT).put("displays", ids).put("screens", screens);
            }

            case "create": {
                AgentDisplay display = AgentDisplay.create(context, request.getInt("width"), request.getInt("height"), request.getInt("dpi"));
                synchronized (displays) {
                    displays.put(display.id, display);
                }
                return ok().put("id", display.id).put("trusted", display.trusted).put("keep_awake", display.keepAwake)
                    .put("keep_awake_reason", display.keepAwakeReason);
            }

            case "release": {
                AgentDisplay display;
                synchronized (displays) {
                    display = displays.remove(request.getInt("id"));
                }
                if (display != null) {
                    display.release();
                }
                return ok().put("released", display != null);
            }

            case "screenshot": {
                AgentDisplay display;
                synchronized (displays) {
                    display = displays.get(request.getInt("id"));
                }
                if (display == null) {
                    return error("no background screen " + request.getInt("id") + " (the helper may have restarted)");
                }
                return display.screenshot(request.getInt("max_short"), request.getInt("max_long"), request.optInt("quality", 80)).put("ok", true);
            }

            case "tree": {
                // The main screen (0), or a background screen of this helper's: no one else's display.
                int id = request.getInt("id");
                synchronized (displays) {
                    if (id != MAIN_DISPLAY && !displays.containsKey(id)) {
                        return error("no background screen " + id + " (the helper may have restarted)");
                    }
                }
                // The display's own size, rotation included: the tree's nodes need not cover it (a
                // game's window can stop short of the bottom), so its extent is not the screen's.
                int[] size = AgentDisplay.logicalSize(id);
                return ok().put("xml", ViewTree.dump(context, id)).put("width", size[0]).put("height", size[1]);
            }

            case "type": {
                // Same screens as "tree": the main one, or one of this helper's.
                int id = request.getInt("id");
                synchronized (displays) {
                    if (id != MAIN_DISPLAY && !displays.containsKey(id)) {
                        return error("no background screen " + id + " (the helper may have restarted)");
                    }
                }
                return ViewTree.type(context, id, request.getString("text")).put("ok", true);
            }

            case "activity": {
                AgentDisplay display;
                synchronized (displays) {
                    display = displays.get(request.getInt("id"));
                }
                if (display == null) {
                    return error("no background screen " + request.getInt("id") + " (the helper may have restarted)");
                }
                return ok().put("reported", display.reportActivity()).put("screen", display.status());
            }

            case "quit":
                return ok();

            default:
                return error("unknown command '" + cmd + "'");
        }
    }

    private void watchIdle() {
        Thread watcher = new Thread(() -> {
            while (true) {
                try {
                    Thread.sleep(30_000);
                } catch (InterruptedException e) {
                    return;
                }
                if (System.currentTimeMillis() - lastRequest > IDLE_MS) {
                    System.out.println("idle for " + IDLE_MS / 60_000 + " minutes, exiting");
                    releaseAll();
                    System.exit(0);
                }
            }
        }, "pig-helper-idle");
        watcher.setDaemon(true);
        watcher.start();
    }

    private void releaseAll() {
        synchronized (displays) {
            for (AgentDisplay display : displays.values()) {
                display.release();
            }
            displays.clear();
        }
    }

    private static JSONObject ok() throws Exception {
        return new JSONObject().put("ok", true);
    }

    private static JSONObject error(String message) {
        try {
            return new JSONObject().put("ok", false).put("error", message);
        } catch (Exception e) {
            throw new IllegalStateException(e);
        }
    }
}
