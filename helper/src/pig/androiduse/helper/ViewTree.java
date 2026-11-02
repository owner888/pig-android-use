package pig.androiduse.helper;

import android.accessibilityservice.AccessibilityServiceInfo;
import android.app.UiAutomation;
import android.content.Context;
import android.graphics.Rect;
import android.os.Bundle;
import android.os.HandlerThread;
import android.os.Looper;
import android.util.SparseArray;
import android.view.accessibility.AccessibilityNodeInfo;
import android.view.accessibility.AccessibilityWindowInfo;

import java.lang.reflect.Constructor;
import java.lang.reflect.Method;
import java.util.List;

import org.json.JSONObject;

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

    /** Work done while UiAutomation is connected, on the windows of one display (null: none). */
    private interface Work<T> {
        T run(List<AccessibilityWindowInfo> windows) throws Exception;
    }

    private static <T> T with(Context context, int displayId, Work<T> work) throws Exception {
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
            return work.run(all.get(displayId));
        } finally {
            disconnect(automation);
            thread.quitSafely();
        }
    }

    static String dump(Context context, int displayId) throws Exception {
        return with(context, displayId, windows -> {
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
        });
    }

    /**
     * Puts text into the input field that has focus on a display, at its cursor (over its
     * selection), by setting the field's text — no keyboard, no clipboard. `input text` sends key
     * events, which carry ASCII only; pasting reads the clipboard, which Android (10 and later)
     * keeps from an app that does not have the focus, and an app on a background screen does not.
     * Then the field is read back, so the reply says whether the text is really there.
     *
     * A field drawn by the app itself (a game, a Unity or Flutter canvas) is no node, so there is
     * nothing to set: `typed` false, and pig falls back to the clipboard.
     */
    static JSONObject type(Context context, int displayId, String text) throws Exception {
        return with(context, displayId, windows -> {
            JSONObject reply = new JSONObject();
            AccessibilityNodeInfo field = null;

            if (windows != null) {
                for (AccessibilityWindowInfo window : windows) {
                    AccessibilityNodeInfo root = window.getRoot();
                    AccessibilityNodeInfo focused = root == null ? null : root.findFocus(AccessibilityNodeInfo.FOCUS_INPUT);
                    if (focused != null && focused.isEditable()) {
                        field = focused;
                        break;
                    }
                }
            }

            if (field == null) {
                return reply.put("typed", false).put("reason", "no input field has the focus on this screen");
            }

            CharSequence shown = field.getText();
            String before = shown == null || field.isShowingHintText() ? "" : shown.toString();
            int start = field.getTextSelectionStart();
            int end = field.getTextSelectionEnd();
            if (start < 0 || end < 0 || start > before.length() || end > before.length()) {
                start = before.length();
                end = before.length();
            }
            int from = Math.min(start, end);
            int to = Math.max(start, end);

            Bundle args = new Bundle();
            args.putCharSequence(AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE, before.substring(0, from) + text + before.substring(to));
            if (!field.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, args)) {
                return reply.put("typed", false).put("reason", "the input field did not take the text");
            }

            // The cursor after what was typed, as a keyboard leaves it: the next text goes on from there.
            int caret = from + text.length();
            Bundle selection = new Bundle();
            selection.putInt(AccessibilityNodeInfo.ACTION_ARGUMENT_SELECTION_START_INT, caret);
            selection.putInt(AccessibilityNodeInfo.ACTION_ARGUMENT_SELECTION_END_INT, caret);
            field.performAction(AccessibilityNodeInfo.ACTION_SET_SELECTION, selection);

            reply.put("typed", true);
            String id = field.getViewIdResourceName();
            reply.put("field", id != null ? id : String.valueOf(field.getClassName()));

            if (field.isPassword()) {
                // Read back as dots: nothing to compare.
                return reply.put("verified", JSONObject.NULL);
            }
            field.refresh();
            CharSequence now = field.getText();
            return reply.put("verified", now != null && now.toString().contains(text)).put("text", now == null ? "" : now.toString());
        });
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
