package pig.androiduse.helper;

import android.accessibilityservice.AccessibilityServiceInfo;
import android.app.UiAutomation;
import android.content.Context;
import android.graphics.Rect;
import android.os.HandlerThread;
import android.os.Looper;
import android.util.SparseArray;
import android.view.accessibility.AccessibilityNodeInfo;
import android.view.accessibility.AccessibilityWindowInfo;

import java.lang.reflect.Constructor;
import java.lang.reflect.Method;
import java.util.List;

/**
 * The view tree of one display, in the XML `uiautomator dump` writes — so pig reads it with the
 * same parser as the main screen's. `uiautomator dump` itself reads the main screen only.
 *
 * Read through UiAutomation, as uiautomator does. The system allows one UiAutomation at a time, so
 * it is connected for each read and disconnected after: held open, it would stop `uiautomator dump`
 * on the main screen from working. It connects with FLAG_DONT_SUPPRESS_ACCESSIBILITY_SERVICES, so
 * the accessibility services the person has on keep running.
 */
final class ViewTree {
    // UiAutomation.FLAG_DONT_SUPPRESS_ACCESSIBILITY_SERVICES
    private static final int DONT_SUPPRESS_ACCESSIBILITY_SERVICES = 1;
    private static final int MAX_NODES = 5000;
    private static final int MAX_DEPTH = 80;

    private ViewTree() {
    }

    static String dump(Context context, int displayId) throws Exception {
        HandlerThread thread = new HandlerThread("pig-uiautomation");
        thread.start();
        UiAutomation automation = create(thread.getLooper(), context);

        try {
            connect(automation);

            AccessibilityServiceInfo info = automation.getServiceInfo();
            info.flags |= AccessibilityServiceInfo.FLAG_RETRIEVE_INTERACTIVE_WINDOWS
                | AccessibilityServiceInfo.FLAG_INCLUDE_NOT_IMPORTANT_VIEWS
                | AccessibilityServiceInfo.FLAG_REPORT_VIEW_IDS;
            automation.setServiceInfo(info);

            try {
                automation.waitForIdle(100, 1000);
            } catch (Exception e) {
                // Busy for a second (an animation, a video): read what is there now.
            }

            SparseArray<List<AccessibilityWindowInfo>> all = automation.getWindowsOnAllDisplays();
            List<AccessibilityWindowInfo> windows = all.get(displayId);

            StringBuilder xml = new StringBuilder(64 * 1024);
            xml.append("<?xml version='1.0' encoding='UTF-8' standalone='yes' ?><hierarchy rotation=\"0\">");
            int[] count = {0};

            if (windows != null) {
                // Bottom to top, as they are drawn: the app first, a dialog or keyboard over it after.
                for (int i = windows.size() - 1; i >= 0; i--) {
                    AccessibilityNodeInfo root = windows.get(i).getRoot();
                    if (root != null) {
                        node(root, 0, 0, xml, count);
                    }
                }
            }

            xml.append("</hierarchy>");
            return xml.toString();
        } finally {
            disconnect(automation);
            thread.quitSafely();
        }
    }

    private static void node(AccessibilityNodeInfo n, int index, int depth, StringBuilder xml, int[] count) {
        if (count[0]++ >= MAX_NODES || depth > MAX_DEPTH) {
            return;
        }

        Rect bounds = new Rect();
        n.getBoundsInScreen(bounds);

        xml.append("<node index=\"").append(index).append('"');
        attr(xml, "text", n.getText());
        attr(xml, "resource-id", n.getViewIdResourceName());
        attr(xml, "class", n.getClassName());
        attr(xml, "package", n.getPackageName());
        attr(xml, "content-desc", n.getContentDescription());
        flag(xml, "checkable", n.isCheckable());
        flag(xml, "checked", n.isChecked());
        flag(xml, "clickable", n.isClickable());
        flag(xml, "enabled", n.isEnabled());
        flag(xml, "focusable", n.isFocusable());
        flag(xml, "focused", n.isFocused());
        flag(xml, "scrollable", n.isScrollable());
        flag(xml, "long-clickable", n.isLongClickable());
        flag(xml, "password", n.isPassword());
        flag(xml, "selected", n.isSelected());
        xml.append(" bounds=\"[").append(bounds.left).append(',').append(bounds.top).append("][")
            .append(bounds.right).append(',').append(bounds.bottom).append("]\"");

        int children = n.getChildCount();
        if (children == 0) {
            xml.append(" />");
            return;
        }

        xml.append('>');
        for (int i = 0; i < children; i++) {
            AccessibilityNodeInfo child = n.getChild(i);
            if (child != null) {
                node(child, i, depth + 1, xml, count);
            }
        }
        xml.append("</node>");
    }

    private static void attr(StringBuilder xml, String name, CharSequence value) {
        xml.append(' ').append(name).append("=\"");
        if (value != null) {
            String s = value.toString();
            for (int i = 0; i < s.length(); i++) {
                char c = s.charAt(i);
                switch (c) {
                    case '&': xml.append("&amp;"); break;
                    case '<': xml.append("&lt;"); break;
                    case '>': xml.append("&gt;"); break;
                    case '"': xml.append("&quot;"); break;
                    case '\n': xml.append("&#10;"); break;
                    case '\r': xml.append("&#13;"); break;
                    case '\t': xml.append("&#9;"); break;
                    default:
                        // XML 1.0 has no place for the other control characters.
                        if (c >= 0x20 || c == 0x09) {
                            xml.append(c);
                        }
                }
            }
        }
        xml.append('"');
    }

    private static void flag(StringBuilder xml, String name, boolean value) {
        xml.append(' ').append(name).append("=\"").append(value).append('"');
    }

    /**
     * UiAutomation's constructors are hidden: (Looper, IUiAutomationConnection) as uiautomator's
     * shell wrapper uses it, or (Context, IUiAutomationConnection) where a release has only that.
     */
    private static UiAutomation create(Looper looper, Context context) throws Exception {
        Object connection = Class.forName("android.app.UiAutomationConnection").getDeclaredConstructor().newInstance();
        Class<?> connectionType = Class.forName("android.app.IUiAutomationConnection");

        for (Class<?> first : new Class<?>[] {Looper.class, Context.class}) {
            try {
                Constructor<UiAutomation> constructor = UiAutomation.class.getDeclaredConstructor(first, connectionType);
                constructor.setAccessible(true);
                return constructor.newInstance(first == Looper.class ? looper : context, connection);
            } catch (NoSuchMethodException e) {
                // the other one
            }
        }

        throw new NoSuchMethodException("UiAutomation has neither (Looper, IUiAutomationConnection) nor (Context, IUiAutomationConnection)");
    }

    private static void connect(UiAutomation automation) throws Exception {
        try {
            Method connect = UiAutomation.class.getDeclaredMethod("connect", int.class);
            connect.setAccessible(true);
            connect.invoke(automation, DONT_SUPPRESS_ACCESSIBILITY_SERVICES);
        } catch (NoSuchMethodException e) {
            throw new NoSuchMethodException("UiAutomation.connect(int) is not there; the view tree cannot be read without suppressing the person's accessibility services");
        }
    }

    private static void disconnect(UiAutomation automation) {
        try {
            Method disconnect = UiAutomation.class.getDeclaredMethod("disconnect");
            disconnect.setAccessible(true);
            disconnect.invoke(automation);
        } catch (Exception e) {
            System.err.println("UiAutomation.disconnect failed: " + AgentDisplay.cause(e));
        }
    }
}
