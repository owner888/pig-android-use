package pig.androiduse.helper;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.PixelFormat;
import android.hardware.display.DisplayManager;
import android.hardware.display.VirtualDisplay;
import android.media.Image;
import android.media.ImageReader;
import android.os.Build;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.IBinder;
import android.os.SystemClock;
import android.util.Base64;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.lang.reflect.Constructor;
import java.lang.reflect.Method;
import java.nio.ByteBuffer;
import java.util.Arrays;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

/**
 * pig's background screen: a virtual display whose picture goes to an ImageReader in this
 * process, so it is never shown on the phone and its screenshots need nothing from the system.
 * The latest frame is kept; a screenshot is that frame, scaled and as JPEG.
 */
final class AgentDisplay {
    // DisplayManager.VIRTUAL_DISPLAY_FLAG_*; the hidden ones by value.
    private static final int FLAG_PUBLIC = 1;
    private static final int FLAG_OWN_CONTENT_ONLY = 1 << 3;
    private static final int FLAG_SUPPORTS_TOUCH = 1 << 6;
    private static final int FLAG_ROTATES_WITH_CONTENT = 1 << 7;
    private static final int FLAG_DESTROY_CONTENT_ON_REMOVAL = 1 << 8;
    private static final int FLAG_TRUSTED = 1 << 10;
    private static final int FLAG_OWN_DISPLAY_GROUP = 1 << 11;
    private static final int FLAG_ALWAYS_UNLOCKED = 1 << 12;
    private static final int FLAG_TOUCH_FEEDBACK_DISABLED = 1 << 13;
    private static final int FLAG_OWN_FOCUS = 1 << 14;
    private static final int FLAG_STEAL_TOP_FOCUS_DISABLED = 1 << 16;

    // WindowManager.DISPLAY_IME_POLICY_LOCAL: a keyboard for a field on this screen opens on this
    // screen, not over the person's.
    private static final int IME_POLICY_LOCAL = 0;

    // A screen in a display group of its own has its own sleep timer, and injected input does
    // not count as use: without a user-activity report it goes to sleep and stops drawing.
    private static final long AWAKE_PERIOD_MS = 4_000;
    private static final int USER_ACTIVITY_EVENT_TOUCH = 2;
    private static final int DEFAULT_DISPLAY_GROUP = 0;

    final int id;
    final boolean trusted;
    final boolean keepAwake;
    /** Why keepAwake is what it is: the display group and whether the per-display report exists. */
    final String keepAwakeReason;

    private final VirtualDisplay display;
    private final ImageReader reader;
    private final HandlerThread thread;
    private final ScheduledExecutorService awake;
    private final Object frameLock = new Object();
    private Bitmap frame;
    private int width;
    private int height;

    private AgentDisplay(VirtualDisplay display, ImageReader reader, HandlerThread thread, boolean trusted, int width, int height) {
        this.display = display;
        this.reader = reader;
        this.thread = thread;
        this.trusted = trusted;
        this.width = width;
        this.height = height;
        this.id = display.getDisplay().getDisplayId();

        setImePolicy(id);
        Method userActivity = userActivityMethod();
        int group = displayGroup(id);
        this.keepAwake = trusted && userActivity != null && group != DEFAULT_DISPLAY_GROUP;
        this.keepAwakeReason = "trusted=" + trusted + " group=" + group + " per-display userActivity=" + (userActivity != null);
        System.out.println("display " + id + " created, keep-awake " + keepAwake + " (" + keepAwakeReason + ")");
        this.awake = keepAwake ? Executors.newSingleThreadScheduledExecutor() : null;
        if (awake != null) {
            Object power = powerManager();
            awake.scheduleWithFixedDelay(() -> {
                try {
                    userActivity.invoke(power, id, SystemClock.uptimeMillis(), USER_ACTIVITY_EVENT_TOUCH, 0);
                } catch (Exception e) {
                    System.err.println("keep-awake for display " + id + " failed: " + cause(e));
                }
            }, 0, AWAKE_PERIOD_MS, TimeUnit.MILLISECONDS);
        }

        reader.setOnImageAvailableListener(r -> keepLatest(), new Handler(thread.getLooper()));
    }

    static AgentDisplay create(Context context, int width, int height, int dpi) throws Exception {
        DisplayManager manager = displayManager(context);

        HandlerThread thread = new HandlerThread("pig-display");
        thread.start();
        ImageReader reader = ImageReader.newInstance(width, height, PixelFormat.RGBA_8888, 2);

        VirtualDisplay display;
        boolean trusted = true;
        try {
            display = manager.createVirtualDisplay("pig-background", width, height, dpi, reader.getSurface(), flags(true));
        } catch (SecurityException e) {
            // Some systems do not give the shell ADD_TRUSTED_DISPLAY: without the trusted-only
            // flags the screen still works, but shares focus with the person's.
            System.err.println("trusted display refused, creating an untrusted one: " + e.getMessage());
            trusted = false;
            display = manager.createVirtualDisplay("pig-background", width, height, dpi, reader.getSurface(), flags(false));
        }

        if (display == null) {
            reader.close();
            thread.quitSafely();
            throw new IllegalStateException("createVirtualDisplay returned null");
        }

        return new AgentDisplay(display, reader, thread, trusted, width, height);
    }

    /**
     * A DisplayManager made on the shell context itself. `getSystemService()` would hand back the
     * one bound to the system context underneath, which reports the package "android": the system
     * then refuses the display ("packageName must match the owner uid"), the helper being uid 2000.
     */
    private static DisplayManager displayManager(Context context) throws Exception {
        Constructor<DisplayManager> constructor = DisplayManager.class.getDeclaredConstructor(Context.class);
        constructor.setAccessible(true);
        return constructor.newInstance(context);
    }

    private static int flags(boolean trusted) {
        int flags = FLAG_PUBLIC | FLAG_OWN_CONTENT_ONLY | FLAG_SUPPORTS_TOUCH | FLAG_ROTATES_WITH_CONTENT | FLAG_DESTROY_CONTENT_ON_REMOVAL;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            flags |= FLAG_TOUCH_FEEDBACK_DISABLED;
            if (trusted) {
                flags |= FLAG_TRUSTED | FLAG_OWN_DISPLAY_GROUP | FLAG_ALWAYS_UNLOCKED;
            }
        }
        // With its own focus, an app opening on this screen would take the top focus from the
        // person's screen; STEAL_TOP_FOCUS_DISABLED stops that.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE && trusted) {
            flags |= FLAG_OWN_FOCUS | FLAG_STEAL_TOP_FOCUS_DISABLED;
        }
        return flags;
    }

    /** Copy the newest frame out of the reader, so a screenshot never waits for the screen to change. */
    private void keepLatest() {
        try (Image image = reader.acquireLatestImage()) {
            if (image == null) {
                return;
            }
            Image.Plane plane = image.getPlanes()[0];
            ByteBuffer buffer = plane.getBuffer();
            int pixelStride = plane.getPixelStride();
            int rowWidth = plane.getRowStride() / pixelStride;

            synchronized (frameLock) {
                if (frame == null || frame.getWidth() != rowWidth || frame.getHeight() != image.getHeight()) {
                    frame = Bitmap.createBitmap(rowWidth, image.getHeight(), Bitmap.Config.ARGB_8888);
                }
                frame.copyPixelsFromBuffer(buffer);
                width = image.getWidth();
                height = image.getHeight();
            }
        }
    }

    /** The latest frame scaled to fit max_short × max_long, as base64 JPEG with both sizes. */
    JSONObject screenshot(int maxShort, int maxLong, int quality) throws Exception {
        Bitmap cropped;
        int rawWidth;
        int rawHeight;

        synchronized (frameLock) {
            if (frame == null) {
                throw new IllegalStateException("nothing has been drawn on the background screen yet; launch an app on it first");
            }
            rawWidth = width;
            rawHeight = height;
            cropped = Bitmap.createBitmap(frame, 0, 0, rawWidth, rawHeight);
        }

        double scale = Math.min(1.0, Math.min(
            (double) maxShort / Math.min(rawWidth, rawHeight),
            (double) maxLong / Math.max(rawWidth, rawHeight)));
        int outWidth = Math.max(1, (int) Math.round(rawWidth * scale));
        int outHeight = Math.max(1, (int) Math.round(rawHeight * scale));
        Bitmap scaled = scale < 1.0 ? Bitmap.createScaledBitmap(cropped, outWidth, outHeight, true) : cropped;

        ByteArrayOutputStream jpeg = new ByteArrayOutputStream();
        scaled.compress(Bitmap.CompressFormat.JPEG, quality, jpeg);

        return new JSONObject()
            .put("width", outWidth)
            .put("height", outHeight)
            .put("raw_width", rawWidth)
            .put("raw_height", rawHeight)
            .put("jpeg", Base64.encodeToString(jpeg.toByteArray(), Base64.NO_WRAP));
    }

    void release() {
        if (awake != null) {
            awake.shutdownNow();
        }
        display.release();
        reader.close();
        thread.quitSafely();
    }

    /**
     * IWindowManager straight from ServiceManager. WindowManagerGlobal would give the same service,
     * but on Android 16 and later getting it there first sets up ApplicationSharedMemory, which only
     * an app process has ("ApplicationSharedMemory not initialized" under app_process).
     */
    private static void setImePolicy(int displayId) {
        try {
            IBinder binder = (IBinder) Class.forName("android.os.ServiceManager").getMethod("getService", String.class).invoke(null, "window");
            Object wm = Class.forName("android.view.IWindowManager$Stub").getMethod("asInterface", IBinder.class).invoke(null, binder);
            wm.getClass().getMethod("setDisplayImePolicy", int.class, int.class).invoke(wm, displayId, IME_POLICY_LOCAL);
        } catch (Exception e) {
            System.err.println("setDisplayImePolicy failed for display " + displayId + ": " + cause(e));
        }
    }

    /** DisplayInfo.displayGroupId (hidden); DEFAULT_DISPLAY_GROUP when it cannot be read, so nothing pokes the person's screen. */
    private static int displayGroup(int displayId) {
        try {
            Object global = Class.forName("android.hardware.display.DisplayManagerGlobal").getMethod("getInstance").invoke(null);
            Object info = global.getClass().getMethod("getDisplayInfo", int.class).invoke(global, displayId);
            return info.getClass().getField("displayGroupId").getInt(info);
        } catch (Exception e) {
            System.err.println("displayGroupId unreadable for display " + displayId + ": " + cause(e));
            return DEFAULT_DISPLAY_GROUP;
        }
    }

    /** The exception a reflective call threw, rather than the InvocationTargetException around it. */
    static Throwable cause(Throwable t) {
        return t instanceof java.lang.reflect.InvocationTargetException && t.getCause() != null ? t.getCause() : t;
    }

    private static Object powerManager() {
        try {
            IBinder binder = (IBinder) Class.forName("android.os.ServiceManager").getMethod("getService", String.class).invoke(null, "power");
            return Class.forName("android.os.IPowerManager$Stub").getMethod("asInterface", IBinder.class).invoke(null, binder);
        } catch (Exception e) {
            throw new IllegalStateException("IPowerManager unavailable", e);
        }
    }

    /**
     * IPowerManager's per-display `userActivity(int displayId, long time, int event, int flags)`
     * (Android 12 on). The older overload without a display reports to the person's screen and
     * would keep it on, so it is not used: null then, and the screen may sleep.
     */
    private static Method userActivityMethod() {
        try {
            Class<?> power = Class.forName("android.os.IPowerManager");
            Class<?>[] perDisplay = {int.class, long.class, int.class, int.class};
            for (Method method : power.getMethods()) {
                if (method.getName().equals("userActivity") && Arrays.equals(method.getParameterTypes(), perDisplay)) {
                    return method;
                }
            }
        } catch (ClassNotFoundException e) {
            System.err.println("IPowerManager not found: " + e);
        }
        return null;
    }
}
