package pig.androiduse.helper;

import android.content.AttributionSource;
import android.content.Context;
import android.content.ContextWrapper;
import android.content.pm.ApplicationInfo;
import android.os.Build;

import java.lang.reflect.Constructor;
import java.lang.reflect.Field;

/**
 * A system context for a process started with app_process, which has none of its own. System
 * services check that the calling package belongs to the calling uid, so this answers as
 * com.android.shell (uid 2000), which is who the helper runs as.
 *
 * The way to build it is the one scrcpy made known (an ActivityThread made by hand, its system
 * context, the shell's package name).
 */
final class ShellContext extends ContextWrapper {
    private static final String SHELL_PACKAGE = "com.android.shell";
    private static final int SHELL_UID = 2000;

    private ShellContext(Context base) {
        super(base);
    }

    @Override
    public String getPackageName() {
        return SHELL_PACKAGE;
    }

    @Override
    public String getOpPackageName() {
        return SHELL_PACKAGE;
    }

    @Override
    public AttributionSource getAttributionSource() {
        return new AttributionSource.Builder(SHELL_UID).setPackageName(SHELL_PACKAGE).build();
    }

    @Override
    public Context getApplicationContext() {
        return this;
    }

    static Context create() throws Exception {
        Class<?> activityThread = Class.forName("android.app.ActivityThread");
        Object thread = activityThread.getMethod("currentActivityThread").invoke(null);
        if (thread == null) {
            thread = newActivityThread(activityThread);
        }
        Context system = (Context) activityThread.getDeclaredMethod("getSystemContext").invoke(thread);
        return new ShellContext(system);
    }

    private static Object newActivityThread(Class<?> activityThread) throws Exception {
        Constructor<?> constructor = activityThread.getDeclaredConstructor();
        constructor.setAccessible(true);
        Object thread = constructor.newInstance();

        field(activityThread, "sCurrentActivityThread").set(null, thread);
        field(activityThread, "mSystemThread").setBoolean(thread, true);

        // Android 12 moved the configuration into a controller that getSystemContext() reads.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            Class<?> controller = Class.forName("android.app.ConfigurationController");
            Class<?> internal = Class.forName("android.app.ActivityThreadInternal");
            Constructor<?> make = controller.getDeclaredConstructor(internal);
            make.setAccessible(true);
            field(activityThread, "mConfigurationController").set(thread, make.newInstance(thread));
        }

        Class<?> bindData = Class.forName("android.app.ActivityThread$AppBindData");
        Constructor<?> makeData = bindData.getDeclaredConstructor();
        makeData.setAccessible(true);
        Object data = makeData.newInstance();
        ApplicationInfo info = new ApplicationInfo();
        info.packageName = SHELL_PACKAGE;
        field(bindData, "appInfo").set(data, info);
        field(activityThread, "mBoundApplication").set(thread, data);

        return thread;
    }

    private static Field field(Class<?> owner, String name) throws NoSuchFieldException {
        Field field = owner.getDeclaredField(name);
        field.setAccessible(true);
        return field;
    }
}
